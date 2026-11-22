<?php
declare(strict_types = 1);
namespace App;
final class PagePolicy {
    public static function catalog():array {
        $pages = [
        ];
        foreach (Menu::GROUPS as $items) foreach ($items as $key => $label) {
            if (Menu::permissionPage($key) !== $key) continue;
            $pages['rental.'.$key] = [
                'title' => $label,
                'module' => 'Аренда',
                'url' => '/rental/'.$key,
                'read' => 'rental.read',
                'edit' => [
                    'rental.write',
                    'rental.approve.legal',
                    'finance.write',
                    'rental.approve.executive'
                ]
            ];
        }
        foreach ([
            'onec' => '1С',
            'onec-yakovlev' => '1С ИП Яковлев',
            'diadoc' => 'Диадок',
            'mosenergo' => 'Мосэнергосбыт',
            'mosvodokanal' => 'Мосводоканал',
            'pik' => 'ПИК-Комфорт',
            'bank' => 'Банковская выписка · 1С'
        ]as $key => $label)$pages['connections.'
        . $key] = [
            'title' => $label,
            'module' => 'Подключения',
            'url' => '/connections/'
            . $key,
            'read' => 'integrations.read',
            'edit' => [
                'integrations.write'
            ]
        ];
        foreach ([
            'email' => 'Email',
            'max' => 'MAX'
        ]as $key => $label)$pages['connections.'
        . $key] = [
            'title' => $label,
            'module' => 'Сообщения',
            'url' => '/'
            . $key,
            'read' => 'messaging.read',
            'edit' => [
                'messaging.write',
                'messaging.send'
            ]
        ];
        foreach ([
            'index' => 'Дополнительные разделы',
            'premises' => 'Помещения',
            'records' => 'Реестры источников',
            'analytics' => 'Аналитика аренды',
            'quality' => 'Проверка данных',
            'market-dashboard' => 'Объявления',
            'market-runs' => 'Запуски',
            'market-comparison' => 'Сравнение',
            'market-history' => 'Динамика',
            'market-settings' => 'Настройки рынка'
        ]as $key => $label)$pages['auxiliary.'
        . $key] = [
            'title' => $label,
            'module' => 'Источники',
            'url' => $key === 'index'?'/auxiliary':('/auxiliary/'
            . str_replace('market-',
            'market/',
            $key)),
            'read' => 'rental.read',
            'edit' => [
                'premises.write'
            ]
        ];
        $pages['core.permissions'] = [
            'title' => 'Права доступа',
            'module' => 'Администрирование',
            'url' => '/permissions',
            'read' => 'identity.permissions',
            'edit' => [
            ]
        ];
        $pages['core.zero-point']=['title'=>'Нулевая точка','module'=>'Администрирование',
            'url'=>'/administration/zero-point','read'=>'identity.permissions','edit'=>['identity.permissions']];
        $pages['connections.schedule']=['title'=>\Modules\Integrations\CabinetSchedulePage::TITLE,'module'=>'Подключения',
            'url'=>\Modules\Integrations\CabinetSchedulePage::URL,'read'=>'identity.permissions','edit'=>['identity.permissions']];
        foreach(\Modules\Integrations\OnecOperations::CONNECTIONS as $key=>$definition)$pages['connections.'.$key]=[
            'title'=>$definition['title'],'module'=>'Подключения','url'=>'/connections/'.$key,
            'read'=>'integrations.read','edit'=>['integrations.write','finance.write'],
        ];
        return $pages;
    }
    private static function coarse(string $roleId, string $permission):bool {
        $q = DB::pdo()->prepare('SELECT 1 FROM role_permissions WHERE role_id=? AND permission_key IN (?,?) LIMIT 1');
        $q->execute([
            $roleId,
            $permission,
            '*'
        ]);
        return (bool)$q->fetchColumn();
    }
    public static function levelForRole(string $roleId, string $kind, bool $configured, string $key):int {
        if ($roleId === 'admin')return 2;
        $page = self::catalog()[$key] ?? null;
        if (!$page)return 0;
        if ($configured) {
            $q = DB::pdo()->prepare('SELECT level FROM role_page_grants WHERE role_id=? AND page_key=?');
            $q->execute([
                $roleId,
                $key
            ]);
            $level=$q->fetchColumn();
            if ($level===false && $key==='rental.onec-work')
                return self::levelForRole($roleId,$kind,true,'rental.approvals');
            return (int)($level?:0);
        }
        if (!self::coarse($roleId, $page['read']))return 0;
        if (str_starts_with($key, 'rental.') && !Menu::allowed(substr($key, 7), [
            'role' => $kind
        ]))return 0;
        foreach ($page['edit']as $permission)if (self::coarse($roleId, $permission))return 2;
        return 1;
    }
    public static function level(string $key, array $u):int {
        return self::levelForRole($u['role_id'] ?? $u['role'], $u['role'], !empty($u['grants_configured']), $key);
    }
    public static function commandPage(string $action):string {
        if (in_array($action,\Modules\Rental\OnecRequest::ACTIONS,true))return 'rental.onec-work';
        if ($action===\Modules\Rental\OccupiedBilling::SAVE) return 'rental.contracts-active';
        if(str_starts_with($action,'workspace.'))return 'core.zero-point';
        if (in_array($action, ['operation.retry', 'operation.manual', \Modules\Rental\StaffHandoff::PACKET], true))return 'rental.operations';
        if (str_starts_with($action, 'payment.'))return 'rental.invoices';
        if ($action === \Modules\Rental\UpdRegister::SAVE)return 'rental.invoice-list';
        if (str_starts_with($action, 'invoice.'))return 'rental.invoice-list';
        if (str_starts_with($action, 'party.'))return 'rental.parties';
        if (str_starts_with($action, 'organization.'))return 'rental.organization';
        if (str_starts_with($action, 'object.'))return 'rental.objects';
        if (str_starts_with($action, 'cadastre.'))return 'rental.cadastre';
        if (str_starts_with($action, 'template.'))return 'rental.templates';
        if (in_array($action, \Modules\Rental\HandoverQuestion::ACTIONS, true))return 'rental.contracts-active';
        if (in_array($action, \Modules\Rental\ExpiryNotice::ACTIONS, true))return 'rental.contracts-active';
        if (in_array($action, [\Modules\Rental\PremisesActs::COUNT,\Modules\Rental\PremisesActs::CONFIRM], true))return 'rental.contracts-active';
        if ((str_starts_with($action, 'closing.') || str_starts_with($action, 'reconcile.')) || in_array($action, [
            'contract.reconcile',
            'contract.close',
            'contract.return-act'
        ], true))return 'rental.reconciliation';
        if (in_array($action, [
            'contract.create',
            'contract.edit',
            'contract.delete'
        ], true) || (str_starts_with($action, 'booking.') || str_starts_with($action, 'reservation.')))return 'rental.contracts';
        if (str_starts_with($action, 'document.') && in_array($action, [
            'document.create',
            'document.edit',
            'document.apply'
        ], true))return 'rental.contracts-active';
        return 'rental.approvals';
    }
    public static function requireCommand(string $action):void {
        $u = Auth::user();
        if (!$u)throw new \RuntimeException('Требуется вход', 401);
        if (!empty($u['grants_configured']) && (empty($u['workflow_role']) || self::level(self::commandPage($action), $u)<2))throw new \RuntimeException('Недостаточно прав для этой страницы или действия.', 403);
    }
    public static function guard(string $path, string $method):void {
        $u = Auth::user();
        if (!$u || $u['role'] === 'admin' || empty($u['grants_configured']))return;
        $write = !in_array($method, [
            'GET',
            'HEAD',
            'OPTIONS'
        ], true);
        $key = null;
        if ($path === '/rental/action') {
            $action=(string)($_POST['action']??'');
            if (in_array($action,[...\Modules\Rental\RentalServices::ACTIONS,...\Modules\Rental\InitialInvoice::ACTIONS],true)) {
                Auth::requirePermission('rental.read');
                return; // Service checks access to the lease in its current state.
            }
            $key = self::commandPage($action);
        }
        elseif($path === '/integrations/action' || in_array($path, [
            '/integrations/file',
            '/integrations/export'
        ], true)) {
            $provider = (string)($_POST['provider'] ?? $_GET['provider'] ?? '');
            $key = 'connections.'
            . $provider;
        }
        elseif(preg_match('~^/integrations/onec-actions/(onec-counterparty|onec-invoice|onec-print)/([a-z]+)$~D',$path,$m)){
            if($m[2]==='check')$key='connections.'.$m[1];
            else{Auth::requirePermission('rental.read');return;} // The shared owner checks finance/packet authority.
        }
        elseif(preg_match('~^/(?:connections|external-documents)/([a-z-]+)$~D', $path, $m))$key = 'connections.'
        . $m[1];
        elseif(in_array($path, [
            '/email',
            '/max'
        ], true))$key = 'connections.'
        . substr($path, 1);
        elseif(str_starts_with($path,'/administration/zero-point'))$key='core.zero-point';
        elseif($path === '/roles' || $path === '/permissions')$key = 'core.permissions';
        elseif(in_array($path, [
            '/rental/new-contract',
            '/rental/edit-contract'
        ], true))$key = 'rental.contracts';
        elseif(preg_match('~^/rental/(contracts|contracts-active|contracts-completed|approvals|templates|invoices|invoice-list|payments|reconciliation|parties|organization|cadastre|objects|operations|errors|journal|summary)$~D', $path, $m))$key = 'rental.'
        . ($m[1] === 'payments'?'invoices':$m[1]);
        elseif(str_starts_with($path, '/auxiliary')) {
            $name = trim(substr($path, 10), '/');
            $key = 'auxiliary.'
            . ($name === ''?'index':str_replace('/', '-', $name));
        }
        elseif(in_array($path,['/rental/booking-list','/rental/booking'],true)) {
            \Modules\Rental\CustomerBookings::readAccess();
            return;
        }
        elseif(str_starts_with($path, '/rental/')) {
            if (empty($u['workflow_role']))throw new \RuntimeException('Роль не участвует в рабочем процессе аренды.', 403);
            $route = substr($path, 8);
            $referencePages = match ($route) {
                'availability' => [
                    'contracts',
                    'contracts-active'
                ], 'booking' => [
                    'contracts',
                    'summary'
                ], 'booking-action' => [
                    'contracts'
                ], 'document-creator', 'document-next' => [
                    'contracts',
                    'contracts-active',
                    'approvals'
                ], 'party-status' => [
                    'parties'
                ], 'template-editor', 'template-decision', 'template-save' => [
                    'templates'
                ], default => [
                ]
            };
            if ($referencePages) {
                $minimum = in_array($route, [
                    'booking-action',
                    'party-status',
                    'document-creator',
                    'document-next',
                    'template-editor',
                    'template-decision',
                    'template-save'
                ], true)?2:1;
                Auth::requirePermission('rental.read');
                foreach ($referencePages as $page)if (self::level('rental.'
                . $page, $u) >= $minimum)return;
                throw new \RuntimeException('Недостаточно прав для контекста формы или справочника.', 403);
            }
            $fixed = [
                'onec-work'=>'onec-work',
                'party' => 'parties',
                'party-review' => 'parties',
                'edit-party' => 'parties',
                'edit-cadastre' => 'cadastre',
                'cadastre-card' => 'cadastre',
                'edit-object' => 'objects',
                'object' => 'objects',
                'object-inventory' => 'objects',
                'operation' => 'operations',
                'operation-action' => 'operations',
                'operation-manual' => 'operations',
                'journal-entry' => 'journal',
                'edit-organization' => 'organization',
                'reservations' => 'contracts',
                'template' => 'templates',
                'template-base' => 'templates'
            ];
            if (isset($fixed[$route]))$key = 'rental.'
            . $fixed[$route];
            elseif($route === 'action-form')$key = self::commandPage((string)($_GET['action'] ?? ''));
            elseif(in_array($route, [
                'invoice',
                'invoice-pdf',
                'edit-invoice',
                'create-invoice',
                'month-invoices',
                'invoice-html',
                'invoice-compare',
                'payment',
                'pay-invoice',
                'invoice-state',
                'invoice-preview',
                'invoice-report',
                'payment-review',
                'upd',
                'finance-save'
            ], true)) {
                Auth::requirePermission('rental.read');
                foreach ([
                    'rental.invoices',
                    'rental.invoice-list'
                ]as $candidate)if (self::level($candidate, $u) >= ($write?2:1))return;
                throw new \RuntimeException('Недостаточно прав для контекста счёта или платежа.', 403);
            }
            elseif(in_array($route, [
                'contract',
                'expiry-notice',
                'services',
                'service',
                'service-form',
                'acts',
                'conditions',
                'history',
                'documents',
                'document',
                'addendum-form',
                'addendum-action',
                'addendum-preview',
                'generate',
                'download',
                'upload',
                'word-pdf',
                'word-form',
                'word-pack'
            ], true)) {
                // These callbacks enforce Access::contract/document/file from persisted entity state.
                // A query-string context never grants access. No arbitrary rental-page fallback.
                Auth::requirePermission('rental.read');
                return;
            }
            else throw new \RuntimeException('Для страницы не задан допустимый контекст доступа.', 403);
        }
        if ($key !== null && str_starts_with($key, 'rental.') && empty($u['workflow_role']))throw new \RuntimeException('Роль не участвует в рабочем процессе аренды.', 403);
        $minimum = $write || in_array($path, ['/rental/operation-action', '/rental/operation-manual'], true)?2:1;
        if ($key !== null && self::level($key, $u)<$minimum)throw new \RuntimeException('Недостаточно прав для этой страницы или действия.', 403);
    }
}
