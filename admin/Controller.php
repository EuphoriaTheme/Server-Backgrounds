<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\serverbackgrounds;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\Factory as ViewFactory;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Server Backgrounds admin controller.
 *
 * This addon stores per-server and per-egg background settings in Blueprint's key/value store.
 *
 * Keys:
 * - server_background_{uuid}_image_url / server_background_{uuid}_opacity
 * - egg_background_{id}_image_url / egg_background_{id}_opacity
 *
 * For performance, the addon also keeps index keys so it doesn't have to scan every server/egg:
 * - server_background_index (JSON array of server UUIDs)
 * - egg_background_index (JSON array of egg IDs)
 */
class serverbackgroundsExtensionController extends Controller
{
    private const NAMESPACE = 'serverbackgrounds';

    private const KEY_DISABLE_FOR_ADMINS = 'disable_for_admins';

    private const KEY_SERVER_INDEX = 'server_background_index';
    private const KEY_EGG_INDEX = 'egg_background_index';

    private const USER_BACKGROUND_KEY_PREFIX = 'user_server_background_';

    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
        private ConfigRepository $config,
        private SettingsRepositoryInterface $settings,
    ) {}

    public function index(Request $request)
    {
        $this->assertRootAdmin($request);
        $this->initializeDefaultSettings();

        // Only select fields needed by the view and scripts to reduce memory usage.
        $eggs = Egg::query()->select(['id', 'name'])->orderBy('name')->get();
        $servers = Server::query()->select(['uuid', 'name', 'egg_id'])->orderBy('name')->get();

        return $this->view->make('admin.extensions.serverbackgrounds.index', [
            'eggs' => $eggs,
            'servers' => $servers,
            'configuredEggs' => $this->fetchConfiguredEggBackgrounds(),
            'configuredServers' => $this->fetchConfiguredServerBackgrounds(),
            'blueprint' => $this->blueprint,
        ]);
    }

    /**
     * Client settings used by the dashboard wrapper script.
     */
    public function getSettings(Request $request)
    {
        $rawDisable = $this->blueprint->dbGet(self::NAMESPACE, self::KEY_DISABLE_FOR_ADMINS);
        $disableForAdmins = $rawDisable === null || $rawDisable === '' ? false : (bool) ((int) $rawDisable);

        $user = $request->user();
        $userIsAdmin = $user && (bool) $user->root_admin;

        return [
            'disable_for_admins' => $disableForAdmins,
            'user_is_admin' => $userIsAdmin,
        ];
    }

    public function fetchUserServerBackground(Request $request)
    {
        $serverUuid = trim((string) $request->query('server_uuid', ''));
        $this->assertUserOwnsServer($request, $serverUuid);

        return response()->json([
            'success' => true,
            'server_uuid' => $serverUuid,
            'background_url' => (string) $this->blueprint->dbGet(
                self::NAMESPACE,
                $this->userBackgroundKey($request->user()->id, $serverUuid),
                ''
            ),
        ]);
    }

    public function saveUserServerBackground(Request $request)
    {
        $request->validate([
            'server_uuid' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{1,64}$/'],
            'background_url' => ['nullable', 'string', 'max:2048', 'url', 'regex:/^https?:\/\//i'],
        ]);

        $serverUuid = trim((string) $request->input('server_uuid'));
        $this->assertUserOwnsServer($request, $serverUuid);
        $backgroundUrl = trim((string) $request->input('background_url', ''));

        $this->blueprint->dbSet(
            self::NAMESPACE,
            $this->userBackgroundKey($request->user()->id, $serverUuid),
            $backgroundUrl
        );

        return response()->json([
            'success' => true,
            'message' => $backgroundUrl === '' ? 'Server background reset successfully.' : 'Server background saved successfully.',
            'background_url' => $backgroundUrl,
        ]);
    }

    public function fetchUserServerBackgrounds(Request $request)
    {
        $user = $request->user();
        $backgrounds = [];

        $servers = Server::query()
            ->select(['uuid'])
            ->whereHas('users', static fn ($query) => $query->where('users.id', $user->id))
            ->get();

        foreach ($servers as $server) {
            $backgroundUrl = (string) $this->blueprint->dbGet(
                self::NAMESPACE,
                $this->userBackgroundKey($user->id, $server->uuid),
                ''
            );

            if ($backgroundUrl !== '') {
                $backgrounds[$server->uuid] = $backgroundUrl;
            }
        }

        return response()->json(['success' => true, 'backgrounds' => $backgrounds]);
    }

    private function assertUserOwnsServer(Request $request, string $serverUuid): void
    {
        if ($serverUuid === '' || !Server::where('uuid', $serverUuid)
            ->whereHas('users', static fn ($query) => $query->where('users.id', $request->user()->id))
            ->exists()) {
            throw new AccessDeniedHttpException();
        }
    }

    private function userBackgroundKey(int|string $userId, string $serverUuid): string
    {
        return self::USER_BACKGROUND_KEY_PREFIX . $userId . '_' . $serverUuid . '_image_url';
    }

    /**
     * Performance option: when enabled, root admins will not see server backgrounds.
     * This can significantly reduce dashboard load time for installations with many servers.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $this->assertRootAdmin($request);

        $request->validate([
            'disable_for_admins' => 'required|in:0,1',
        ]);

        $this->blueprint->dbSet(
            self::NAMESPACE,
            self::KEY_DISABLE_FOR_ADMINS,
            $request->boolean('disable_for_admins', false) ? '1' : '0'
        );

        return redirect()->back()->with('success', 'Settings updated successfully.');
    }

    public function bulkSaveBackgrounds(Request $request): RedirectResponse
    {
        $this->assertRootAdmin($request);

        $request->validate([
            'backgrounds' => 'required|array',
            'backgrounds.*.server_id' => 'nullable|exists:servers,uuid',
            'backgrounds.*.egg_id' => 'nullable|exists:eggs,id',
            'backgrounds.*.image_url' => 'required|url',
            'backgrounds.*.opacity' => 'nullable|numeric|min:0|max:1',
        ]);

        $serverIndex = $this->getServerIndex();
        $eggIndex = $this->getEggIndex();

        foreach ($request->input('backgrounds') as $background) {
            $serverUuid = $background['server_id'] ?? null;
            $eggId = $background['egg_id'] ?? null;
            $imageUrl = (string) $background['image_url'];
            $opacity = $background['opacity'] ?? 1;

            if ($serverUuid) {
                $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$serverUuid}_image_url", $imageUrl);
                $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$serverUuid}_opacity", (string) $opacity);

                if (!in_array($serverUuid, $serverIndex, true)) {
                    $serverIndex[] = $serverUuid;
                }

                continue;
            }

            if ($eggId) {
                $eggKey = (string) $eggId;

                $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$eggKey}_image_url", $imageUrl);
                $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$eggKey}_opacity", (string) $opacity);

                if (!in_array($eggKey, $eggIndex, true)) {
                    $eggIndex[] = $eggKey;
                }
            }
        }

        $this->setServerIndex($serverIndex);
        $this->setEggIndex($eggIndex);

        return redirect()->back()->with('success', 'Background images saved successfully.');
    }

    public function updateAndDeleteBackgroundSettings(Request $request): RedirectResponse
    {
        $this->assertRootAdmin($request);

        $request->validate([
            'backgrounds' => 'array',
            'backgrounds.*.server_id' => 'nullable|exists:servers,uuid',
            'backgrounds.*.egg_id' => 'nullable|exists:eggs,id',
            'backgrounds.*.image_url' => 'nullable|url',
            'backgrounds.*.opacity' => 'nullable|numeric|min:0|max:1',
            'delete_backgrounds' => 'array',
            'delete_backgrounds.*' => 'string',
        ]);

        $serverIndex = $this->getServerIndex();
        $eggIndex = $this->getEggIndex();

        foreach ($request->input('backgrounds', []) as $background) {
            $serverUuid = $background['server_id'] ?? null;
            $eggId = $background['egg_id'] ?? null;
            $imageUrl = $background['image_url'] ?? null;
            $opacity = $background['opacity'] ?? 1;

            if ($serverUuid) {
                if ($imageUrl) {
                    $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$serverUuid}_image_url", (string) $imageUrl);
                    if (!in_array($serverUuid, $serverIndex, true)) {
                        $serverIndex[] = $serverUuid;
                    }
                }

                $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$serverUuid}_opacity", (string) $opacity);
                continue;
            }

            if ($eggId) {
                $eggKey = (string) $eggId;

                if ($imageUrl) {
                    $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$eggKey}_image_url", (string) $imageUrl);
                    if (!in_array($eggKey, $eggIndex, true)) {
                        $eggIndex[] = $eggKey;
                    }
                }

                $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$eggKey}_opacity", (string) $opacity);
            }
        }

        if ($request->has('delete_backgrounds')) {
            foreach ($request->input('delete_backgrounds') as $id) {
                if (Server::where('uuid', $id)->exists()) {
                    $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$id}_image_url", '');
                    $this->blueprint->dbSet(self::NAMESPACE, "server_background_{$id}_opacity", '');
                    $serverIndex = array_values(array_filter($serverIndex, fn ($uuid) => $uuid !== $id));
                    continue;
                }

                if (Egg::where('id', $id)->exists()) {
                    $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$id}_image_url", '');
                    $this->blueprint->dbSet(self::NAMESPACE, "egg_background_{$id}_opacity", '');
                    $eggIndex = array_values(array_filter($eggIndex, fn ($eggId) => $eggId !== (string) $id));
                }
            }
        }

        $this->setServerIndex($serverIndex);
        $this->setEggIndex($eggIndex);

        return redirect()->back()->with('success', 'Background settings updated successfully.');
    }

    /**
     * Returns configured server backgrounds for use by the dashboard wrapper script and admin UI.
     */
    public function fetchConfiguredServerBackgrounds(): array
    {
        $serverIndex = $this->getServerIndex();
        if ($serverIndex === []) {
            return [];
        }

        $servers = Server::query()->select(['uuid', 'name'])->whereIn('uuid', $serverIndex)->get();
        $configured = [];

        foreach ($servers as $server) {
            $imageUrl = (string) $this->blueprint->dbGet(self::NAMESPACE, "server_background_{$server->uuid}_image_url", '');
            if ($imageUrl === '') {
                continue;
            }

            $opacity = $this->blueprint->dbGet(self::NAMESPACE, "server_background_{$server->uuid}_opacity", 1);

            $configured[] = (object) [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'image_url' => $imageUrl,
                'opacity' => $opacity,
            ];
        }

        return $configured;
    }

    /**
     * Returns configured egg backgrounds for use by the dashboard wrapper script and admin UI.
     */
    public function fetchConfiguredEggBackgrounds(): array
    {
        $eggIndex = $this->getEggIndex();
        if ($eggIndex === []) {
            return [];
        }

        $eggIds = array_map('intval', $eggIndex);
        $eggs = Egg::query()->select(['id', 'name'])->whereIn('id', $eggIds)->get();

        $configured = [];

        foreach ($eggs as $egg) {
            $imageUrl = (string) $this->blueprint->dbGet(self::NAMESPACE, "egg_background_{$egg->id}_image_url", '');
            if ($imageUrl === '') {
                continue;
            }

            $opacity = $this->blueprint->dbGet(self::NAMESPACE, "egg_background_{$egg->id}_opacity", 1);

            $configured[] = (object) [
                'id' => $egg->id,
                'name' => $egg->name,
                'image_url' => $imageUrl,
                'opacity' => $opacity,
            ];
        }

        return $configured;
    }

    private function initializeDefaultSettings(): void
    {
        $current = $this->blueprint->dbGet(self::NAMESPACE, self::KEY_DISABLE_FOR_ADMINS);
        if ($current === null || $current === '') {
            $this->blueprint->dbSet(self::NAMESPACE, self::KEY_DISABLE_FOR_ADMINS, '0');
        }

        // If indexes are missing, keep them null until first use so we can migrate legacy installs.
    }

    private function assertRootAdmin(Request $request): void
    {
        if (!$request->user() || !$request->user()->root_admin) {
            throw new AccessDeniedHttpException();
        }
    }

    private function getServerIndex(): array
    {
        $raw = $this->blueprint->dbGet(self::NAMESPACE, self::KEY_SERVER_INDEX);
        if ($raw === null) {
            $index = $this->buildServerIndexFromLegacy();
            $this->setServerIndex($index);
            return $index;
        }

        return $this->decodeIndex($raw);
    }

    private function setServerIndex(array $uuids): void
    {
        $this->blueprint->dbSet(self::NAMESPACE, self::KEY_SERVER_INDEX, $this->encodeIndex($uuids));
    }

    private function getEggIndex(): array
    {
        $raw = $this->blueprint->dbGet(self::NAMESPACE, self::KEY_EGG_INDEX);
        if ($raw === null) {
            $index = $this->buildEggIndexFromLegacy();
            $this->setEggIndex($index);
            return $index;
        }

        return $this->decodeIndex($raw);
    }

    private function setEggIndex(array $eggIds): void
    {
        $this->blueprint->dbSet(self::NAMESPACE, self::KEY_EGG_INDEX, $this->encodeIndex($eggIds));
    }

    private function buildServerIndexFromLegacy(): array
    {
        $index = [];

        Server::query()->select(['uuid'])->chunk(500, function ($servers) use (&$index) {
            foreach ($servers as $server) {
                $imageUrl = (string) $this->blueprint->dbGet(self::NAMESPACE, "server_background_{$server->uuid}_image_url", '');
                if ($imageUrl !== '') {
                    $index[] = $server->uuid;
                }
            }
        });

        return array_values(array_unique($index));
    }

    private function buildEggIndexFromLegacy(): array
    {
        $index = [];

        Egg::query()->select(['id'])->chunk(200, function ($eggs) use (&$index) {
            foreach ($eggs as $egg) {
                $imageUrl = (string) $this->blueprint->dbGet(self::NAMESPACE, "egg_background_{$egg->id}_image_url", '');
                if ($imageUrl !== '') {
                    $index[] = (string) $egg->id;
                }
            }
        });

        return array_values(array_unique($index));
    }

    private function decodeIndex(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $value) {
            if (is_int($value)) {
                $out[] = (string) $value;
                continue;
            }

            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    private function encodeIndex(array $values): string
    {
        $out = [];

        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $out[] = $value;
        }

        $out = array_values(array_unique($out));

        $json = json_encode($out, JSON_UNESCAPED_SLASHES);
        return $json === false ? '[]' : $json;
    }
}
