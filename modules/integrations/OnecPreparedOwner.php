<?php
declare(strict_types = 1);
namespace Modules\Integrations;
interface OnecPreparedOwner {
    public function prepare(int $actor,
        string $kind,
        array $businessIdentity): array;
    public function current(array $intent,
        string $action): array;
    public function confirmation(array $intent): array;
}
