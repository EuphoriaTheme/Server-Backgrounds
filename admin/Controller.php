<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\{identifier};
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;
use Illuminate\Support\Facades\File;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Illuminate\Http\RedirectResponse;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;

class {identifier}ExtensionController extends Controller
{
    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
        private ConfigRepository $config,
        private SettingsRepositoryInterface $settings,
    ){}

    public function index(Request $request): View
    {
        if (!$request->user() || !$request->user()->root_admin) {
            throw new AccessDeniedHttpException();
        }

        $eggs = Egg::all();
        $servers = Server::all();
        $configuredEggs = $this->fetchConfiguredEggBackgrounds($request);
        $configuredServers = $this->fetchConfiguredServerBackgrounds($request);
    
        return $this->view->make(
            'admin.extensions.{identifier}.index', [
            'eggs' => $eggs,
            'servers' => $servers,
            'configuredEggs' => $configuredEggs,
            'configuredServers' => $configuredServers,
            'root' => "/admin/extensions/{identifier}",
            'blueprint' => $this->blueprint,
        ]);
    } 

    public function bulkSaveBackgrounds(Request $request)
    {
        if (!$request->user() || !$request->user()->root_admin) {
            throw new AccessDeniedHttpException();
        }

        $request->validate([
            'backgrounds' => 'required|array',
            'backgrounds.*.server_id' => 'nullable|exists:servers,uuid',
            'backgrounds.*.egg_id' => 'nullable|exists:eggs,id',
            'backgrounds.*.image_url' => 'required|url',
            'backgrounds.*.opacity' => 'nullable|numeric|min:0|max:1', // Validate opacity value
        ]);

        foreach ($request->input('backgrounds') as $background) {
            $serverUuid = $background['server_id'] ?? null;
            $eggId = $background['egg_id'] ?? null;
            $imageUrl = $background['image_url'];
            $opacity = $background['opacity'] ?? 1;

            if ($serverUuid) {
                // Save the background image URL and opacity for the server UUID
                $server = Server::where('uuid', $serverUuid)->first();
                if ($server) {
                    $this->blueprint->dbSet("serverbackgrounds", "server_background_{$serverUuid}_image_url", $imageUrl);
                    $this->blueprint->dbSet("serverbackgrounds", "server_background_{$serverUuid}_opacity", $opacity);
                }
            } elseif ($eggId) {
                // Save the background image URL and opacity for the egg ID
                $this->blueprint->dbSet("serverbackgrounds", "egg_background_{$eggId}_image_url", $imageUrl);
                $this->blueprint->dbSet("serverbackgrounds", "egg_background_{$eggId}_opacity", $opacity);
            }
        }

        return redirect()->back()->with('success', 'Background images saved successfully.');
    }

    public function updateAndDeleteBackgroundSettings(Request $request)
    {
        if (!$request->user() || !$request->user()->root_admin) {
            throw new AccessDeniedHttpException();
        }

        $request->validate([
            'backgrounds' => 'array',
            'backgrounds.*.server_id' => 'nullable|exists:servers,uuid',
            'backgrounds.*.egg_id' => 'nullable|exists:eggs,id',
            'backgrounds.*.image_url' => 'nullable|url',
            'backgrounds.*.opacity' => 'nullable|numeric|min:0|max:1', // Validate opacity value
            'delete_backgrounds' => 'array',
            'delete_backgrounds.*' => 'string', // Validate as string since it can be either UUID or egg ID
        ]);

        foreach ($request->input('backgrounds', []) as $background) {
            $serverUuid = $background['server_id'] ?? null;
            $eggId = $background['egg_id'] ?? null;
            $imageUrl = $background['image_url'] ?? null;
            $opacity = $background['opacity'] ?? 1;

            if ($serverUuid) {
                // Update the background image URL and opacity for the server UUID
                if ($imageUrl) {
                    $this->blueprint->dbSet("serverbackgrounds", "server_background_{$serverUuid}_image_url", $imageUrl);
                }
                $this->blueprint->dbSet("serverbackgrounds", "server_background_{$serverUuid}_opacity", $opacity);
            } elseif ($eggId) {
                // Update the background image URL and opacity for the egg ID
                if ($imageUrl) {
                    $this->blueprint->dbSet("serverbackgrounds", "egg_background_{$eggId}_image_url", $imageUrl);
                }
                $this->blueprint->dbSet("serverbackgrounds", "egg_background_{$eggId}_opacity", $opacity);
            }
        }

        // Handle deletion of backgrounds
        if ($request->has('delete_backgrounds')) {
            foreach ($request->input('delete_backgrounds') as $id) {
                // Check if the ID is a server UUID or an egg ID
                if (Server::where('uuid', $id)->exists()) {
                    // Clear server background
                    $this->blueprint->dbSet("serverbackgrounds", "server_background_{$id}_image_url", '');
                    $this->blueprint->dbSet("serverbackgrounds", "server_background_{$id}_opacity", '');
                } elseif (Egg::where('id', $id)->exists()) {
                    // Clear egg background
                    $this->blueprint->dbSet("serverbackgrounds", "egg_background_{$id}_image_url", '');
                    $this->blueprint->dbSet("serverbackgrounds", "egg_background_{$id}_opacity", '');
                }
            }
        }

        return redirect()->back()->with('success', 'Background settings updated successfully.');
    }    

    public function fetchConfiguredServerBackgrounds(Request $request = null)
    {
    $servers = Server::all();
    $configuredServers = [];

    foreach ($servers as $server) {
        $imageUrl = $this->blueprint->dbGet("serverbackgrounds", "server_background_{$server->uuid}_image_url", '');
        $opacity = $this->blueprint->dbGet("serverbackgrounds", "server_background_{$server->uuid}_opacity", 1); // Default opacity to 1

        if ($imageUrl) {
            $configuredServers[] = (object) [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'image_url' => $imageUrl,
                'opacity' => $opacity,
            ];
        }
    }

    if ($request && $request->expectsJson()) {
        return response()->json($configuredServers);
    }

    return $configuredServers;
    }
    
    public function fetchConfiguredEggBackgrounds(Request $request = null)
    {
        $eggs = Egg::all();
        $configuredEggs = [];
    
        foreach ($eggs as $egg) {
            $imageUrl = $this->blueprint->dbGet("serverbackgrounds", "egg_background_{$egg->id}_image_url", '');
            $opacity = $this->blueprint->dbGet("serverbackgrounds", "egg_background_{$egg->id}_opacity", 1); // Default opacity to 1
    
            if ($imageUrl) {
                $configuredEggs[] = (object) [
                    'id' => $egg->id,
                    'name' => $egg->name,
                    'image_url' => $imageUrl,
                    'opacity' => $opacity,
                ];
            }
        }
    
        if ($request && $request->expectsJson()) {
            return response()->json($configuredEggs);
        }
    
        return $configuredEggs;
    }
}