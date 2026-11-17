<?php
declare(strict_types = 1);
namespace Modules\Integrations;
final class OnecSetupGap extends \RuntimeException {
    public function __construct(public readonly array $fields) {
        foreach ($fields as $field)if (!is_string($field) || !preg_match('/^[A-Za-z0-9_.:-]{1,200}$/D',
            $field))throw new \InvalidArgumentException('INVALID_GAP_FIELD');
        parent::__construct('SETUP_MAPPING_GAP');
    }
}
