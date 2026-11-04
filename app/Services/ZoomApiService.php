<?php

namespace App\Services;

use App\Models\ZoomIntegrationSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZoomApiService
{
    private const API = 'https://api.zoom.us/v2';

    public function testConnection(): array
    {
        return $this->request('GET', '/users/me');
    }

    public function users(): array
    {
        $users = [];
        $pageToken = null;
        do {
            $params = ['page_size' => 300];
            if ($pageToken) $params['next_page_token'] = $pageToken;
            $response = $this->request('GET', '/users', $params);
            array_push($users, ...($response['users'] ?? []));
            $pageToken = $response['next_page_token'] ?? null;
        } while ($pageToken);
        return $users;
    }

    public function createMeeting(string $hostId, array $payload): array
    {
        return $this->request('POST', '/users/'.rawurlencode($hostId).'/meetings', $payload);
    }

    public function register(string $meetingId, array $payload): array
    {
        return $this->request('POST', '/meetings/'.rawurlencode($meetingId).'/registrants', $payload);
    }

    public function historyMeetings(string $from, string $to, ?string $pageToken = null, ?string $searchKey = null): array
    {
        $query = ['from' => $from, 'to' => $to, 'meeting_type' => 'meeting', 'page_size' => 300];
        if ($pageToken) $query['next_page_token'] = $pageToken;
        if ($searchKey) $query['search_key'] = $searchKey;
        return $this->request('GET', '/report/history_meetings', $query);
    }

    public function pastMeetingParticipants(string $meetingUuid, ?string $pageToken = null): array
    {
        $encodedUuid = rawurlencode($meetingUuid);
        if (str_contains($meetingUuid, '/') || str_contains($meetingUuid, '//')) {
            $encodedUuid = rawurlencode($encodedUuid);
        }
        $query = ['page_size' => 300, 'include_fields' => 'registrant_id'];
        if ($pageToken) $query['next_page_token'] = $pageToken;
        return $this->request('GET', '/past_meetings/'.$encodedUuid.'/participants', $query);
    }

    public function request(string $method, string $path, array $payload = []): array
    {
        $token = $this->accessToken();
        $response = $this->apiRequest($method, $path, $payload, $token);

        if ($response->status() === 401) {
            $this->forgetToken();
            $response = $this->apiRequest($method, $path, $payload, $this->accessToken());
        }

        if (! $response->successful()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['message'] ?? $body['reason'] ?? null) : null;
            $safeMessage = is_string($message) ? mb_substr($message, 0, 240) : 'Zoom rejected the request.';
            Log::warning('Zoom API request failed.', ['path' => $path, 'status' => $response->status(), 'zoom_message' => $safeMessage]);
            throw new ZoomApiException($safeMessage, $response->status());
        }

        return $response->json() ?? [];
    }

    private function apiRequest(string $method, string $path, array $payload, string $token)
    {
        $request = Http::acceptJson()->withToken($token)->timeout(30);
        try {
            return $request->{strtolower($method)}(self::API.$path, $payload);
        } catch (Throwable $exception) {
            Log::warning('Zoom API request failed at the transport layer.', ['path' => $path, 'exception' => $exception::class]);
            throw new ZoomApiException('Could not connect to Zoom. Check the server connection and try again.');
        }
    }

    private function accessToken(): string
    {
        $settings = ZoomIntegrationSetting::query()->first();
        if (! $settings?->account_id || ! $settings?->client_id || ! $settings?->client_secret) {
            throw new ZoomApiException('Save complete Zoom Server-to-Server credentials before connecting.');
        }

        $key = 'zoom.s2s.token.'.hash('sha256', $settings->account_id.'|'.$settings->client_id.'|'.hash('sha256', $settings->client_secret));
        return Cache::remember($key, now()->addMinutes(55), function () use ($settings): string {
            try {
                $response = Http::asForm()->withBasicAuth($settings->client_id, $settings->client_secret)
                    ->acceptJson()->timeout(20)->post('https://zoom.us/oauth/token', [
                        'grant_type' => 'account_credentials',
                        'account_id' => $settings->account_id,
                    ]);
            } catch (Throwable $exception) {
                Log::warning('Zoom OAuth token request failed at the transport layer.', ['exception' => $exception::class]);
                throw new ZoomApiException('Could not connect to Zoom authentication. Check the server connection and try again.');
            }

            $data = $response->json();
            if (! $response->successful() || ! is_array($data) || ! is_string($data['access_token'] ?? null)) {
                Log::warning('Zoom OAuth token request was rejected.', ['status' => $response->status()]);
                throw new ZoomApiException('Zoom authentication failed. Verify the saved credentials and app scopes.', $response->status());
            }

            return $data['access_token'];
        });
    }

    private function forgetToken(): void
    {
        $settings = ZoomIntegrationSetting::query()->first();
        if ($settings) Cache::forget('zoom.s2s.token.'.hash('sha256', $settings->account_id.'|'.$settings->client_id.'|'.hash('sha256', $settings->client_secret)));
    }
}
