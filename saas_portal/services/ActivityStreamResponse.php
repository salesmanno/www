<?php

class ActivityStreamResponse {
    /**
     * Load activities and return the HTTP status and response body for the API.
     */
    public static function fromLoader(callable $loader, ?callable $errorHandler = null) {
        try {
            $result = $loader();
            $activities = is_array($result) && array_key_exists('activities', $result)
                ? $result['activities']
                : $result;

            $body = [
                'success' => true,
                'activities' => $activities
            ];
            if (is_array($result) && array_key_exists('action_types', $result)) {
                $body['action_types'] = $result['action_types'];
            }
            $body['count'] = count($activities);
            $body['timestamp'] = date('Y-m-d H:i:s');

            return ['status' => 200, 'body' => $body];
        } catch (Throwable $e) {
            if ($errorHandler !== null) {
                [$status, $body] = $errorHandler($e);
                return ['status' => $status, 'body' => $body];
            }

            error_log("Get activity stream error: " . $e->getMessage());
            $isDatabaseUnavailable = strpos($e->getMessage(), 'DATABASE TILKOBLINGSFEIL') !== false;

            return [
                'status' => $isDatabaseUnavailable ? 503 : 500,
                'body' => [
                    'success' => false,
                    'message' => $isDatabaseUnavailable
                        ? 'Databasetilkoblingen er utilgjengelig. Prøv igjen senere.'
                        : 'Kunne ikke hente aktivitetsloggen. Prøv igjen.'
                ]
            ];
        }
    }
}