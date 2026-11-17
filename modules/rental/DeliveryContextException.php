<?php
declare(strict_types=1);
namespace Modules\Rental;

final class DeliveryContextException extends \RuntimeException
{
    public function __construct(string $message,public readonly array $missing)
    {
        parent::__construct($message,422);
    }
}
