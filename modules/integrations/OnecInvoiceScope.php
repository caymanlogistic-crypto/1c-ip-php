<?php
declare(strict_types=1);

namespace Modules\Integrations;

/** Native write admission is stricter than preserving a normal legal B confirmation. */
final class OnecInvoiceScope
{
    public static function requireReady(array $invoice):void
    {
        $field='invoice_scope.invoice.'.(int)($invoice['id']??0).'.';
        if(\App\OperatingMembership::selected()!==null&&!is_array($invoice['operating_membership']??null))throw new OnecSetupGap([$field.'operating_membership']);
        try{\App\OperatingInvoiceContext::requireOrdinary($invoice['operating_membership']??null);}
        catch(\RuntimeException){throw new OnecSetupGap([$field.'operating_membership']);}
        $targets=$invoice['billing_targets']??null;
        if($targets!==null&&!is_array($targets))throw new OnecSetupGap([$field.'billing_targets']);
        if(\App\BillingPartAdmission::selected()!==null&&str_starts_with((string)($invoice['coverage_key']??''),'billable-monthly:')&&!is_array($targets))throw new OnecSetupGap([$field.'billing_targets']);
        try{\App\BillingInvoiceTargets::requireReady($targets);}
        catch(\RuntimeException){throw new OnecSetupGap([$field.'billing_targets']);}
        $scope=$invoice['scope_admission']??null;
        if($scope===null)return; // Optional control plane is OFF; no fallback facts are invented.
        if(!is_array($scope))throw new OnecSetupGap([$field.'admission']);
        if(($scope['runtime_hold']??null)!==null&&$scope['runtime_hold']!=='')throw new OnecSetupGap([$field.'runtime_hold']);
        foreach(['availability','objects_state','periods_state']as$key){
            if(($scope[$key]??null)!=='KNOWN')throw new OnecSetupGap([$field.$key]);
        }
        if(!in_array($scope['classification']??null,['RENTAL','NOT_RENTAL_SECURITY'],true)
            ||!is_int($scope['invoice_version']??null)||$scope['invoice_version']!==($invoice['version']??null)
            ||($scope['admission_sha256']??null)!==\App\InvoiceScope::CANDIDATE_MANIFEST
            ||!is_string($scope['evidence_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$scope['evidence_sha256']))throw new OnecSetupGap([$field.'evidence']);
        // KNOWN empty security scope is valid. Occupancy is not a billing filter.
    }
}
