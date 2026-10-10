<?php

declare(strict_types=1);

namespace PushLocal\Service;

final class RetentionManager
{
    private DeploymentStorage $storage;
    private Settings $settings;

    public function __construct(
        ?DeploymentStorage $storage = null,
        ?Settings $settings = null
    ) {
        $this->storage = $storage ?? new DeploymentStorage();
        $this->settings = $settings ?? new Settings();
    }

    public function cleanup(): array
    {
        $policy = $this->settings->all();
        $manifests = $this->storage->listManifests(1000);

        if (is_wp_error($manifests)) {
            return ['deleted' => 0, 'kept' => 0];
        }

        $now = time();
        $terminalSeen = 0;
        $deleted = 0;
        $kept = 0;

        foreach ($manifests as $manifest) {
            $id = (string) ($manifest['id'] ?? '');
            $status = (string) ($manifest['status'] ?? '');
            $createdAt = strtotime((string) ($manifest['created_at'] ?? ''));

            if ($id === '' || $createdAt === false) {
                $kept++;
                continue;
            }

            $ageSeconds = max(0, $now - $createdAt);
            $terminal = in_array(
                $status,
                ['completed', 'rolled_back', 'failed', 'rollback_failed', 'aborted'],
                true
            );

            if ($terminal) {
                $terminalSeen++;

                $tooOld = $ageSeconds > $policy['max_age_days'] * DAY_IN_SECONDS;
                $beyondLatest = $terminalSeen > $policy['keep_latest'];

                if ($tooOld || $beyondLatest) {
                    if ($this->storage->deleteDeploymentData($id)) {
                        $deleted++;
                        continue;
                    }
                }

                $kept++;
                continue;
            }

            if (
                in_array($status, ['created', 'uploading'], true) &&
                $ageSeconds > $policy['abandoned_upload_hours'] * HOUR_IN_SECONDS
            ) {
                if ($this->storage->deleteDeploymentData($id)) {
                    $deleted++;
                    continue;
                }
            }

            $kept++;
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    public function policy(): array
    {
        return $this->settings->all();
    }
}
