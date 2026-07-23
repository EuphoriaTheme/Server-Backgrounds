import React, { useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import ContentBox from '@/components/elements/ContentBox';
import http from '@/api/http';

const ENDPOINT = '/extensions/serverbackgrounds/user/server-background';

const ServerBackgroundSettings: React.FC = () => {
  const serverUuid = ServerContext.useStoreState((state) => state.server.data?.uuid ?? '');
  const [backgroundUrl, setBackgroundUrl] = useState('');
  const [status, setStatus] = useState('');
  const [isSaving, setIsSaving] = useState(false);

  useEffect(() => {
    if (!serverUuid) return;
    let mounted = true;

    http
      .get(ENDPOINT, { params: { server_uuid: serverUuid } })
      .then((response) => {
        if (mounted) setBackgroundUrl(response.data?.background_url ?? '');
      })
      .catch(() => {
        if (mounted) setStatus('Unable to load your server background.');
      });

    return () => {
      mounted = false;
    };
  }, [serverUuid]);

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    const value = backgroundUrl.trim();

    if (value && !/^https?:\/\//i.test(value)) {
      setStatus('Please enter a valid http:// or https:// image URL.');
      return;
    }

    setIsSaving(true);
    setStatus('');
    try {
      await http.post(ENDPOINT, { server_uuid: serverUuid, background_url: value });
      setStatus(value ? 'Background saved. Refresh the servers page to see it.' : 'Background reset successfully.');
    } catch (error: any) {
      setStatus(error?.response?.data?.message ?? 'Unable to save your server background.');
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <ContentBox title='Personal Server Background'>
      <form onSubmit={save} style={{ display: 'grid', gap: '0.75rem' }}>
        <p style={{ margin: 0, color: '#9ca3af' }}>
          Add an image URL to personalize this server on your servers page. Your choice overrides the admin and egg background only for you.
        </p>
        <input
          type='url'
          value={backgroundUrl}
          onChange={(event) => setBackgroundUrl(event.target.value)}
          placeholder='https://example.com/server-background.jpg'
          aria-label='Personal server background URL'
          style={{ width: '100%', padding: '0.65rem', borderRadius: '6px' }}
        />
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          <button type='submit' disabled={isSaving} className='btn btn-primary'>
            {isSaving ? 'Saving...' : 'Save Background'}
          </button>
          <button type='button' disabled={isSaving} className='btn btn-secondary' onClick={() => setBackgroundUrl('')}>
            Reset
          </button>
        </div>
        {status && <small role='status'>{status}</small>}
      </form>
    </ContentBox>
  );
};

export default ServerBackgroundSettings;
