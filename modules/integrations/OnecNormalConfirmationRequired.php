<?php
declare(strict_types = 1);
namespace Modules\Integrations;
final class OnecNormalConfirmationRequired extends \RuntimeException {
    public function __construct(public readonly array $fields = ['confirmation.normal_accountant_packet']) {
        parent::__construct('NORMAL_B_CONFIRMATION_REQUIRED');
    }
}
