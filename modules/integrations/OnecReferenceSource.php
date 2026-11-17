<?php
declare(strict_types = 1);
namespace Modules\Integrations;
interface OnecReferenceSource {
    public function source(): array;
    public function observation(array $source,
        string $collection,
        string $logicalId,
        string $field,
        string $purpose = ''): array;
    public function reference(array $source,
        string $collection,
        string $logicalId,
        string $field,
        string $purpose = ''): array;
    public function partyIdentity(array $source,
        array $party): array;
    public function invoicePolicy(array $source,
        string $kind): array;
}
