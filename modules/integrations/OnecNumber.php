<?php
declare(strict_types = 1);
namespace Modules\Integrations;
/** Explicit exact numeric token for native OData bodies, never a binary float. */
final readonly class OnecNumber {
    public string $decimal;
    public function __construct(string $decimal){
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',
            $decimal))throw new \InvalidArgumentException('ONEC_DECIMAL_INVALID');
        $parts = explode('.',
            $decimal,
            2);
        $this->decimal = (ltrim($parts[0],
            '0')?:'0') . (isset($parts[1])?'.' . $parts[1]:'');
    }
}
