<?php

declare(strict_types=1);

namespace App\Modules\Wallet;

use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Support\Str;
use App\Modules\Payments\PaymentService;

final class WalletController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $wallet = $this->c->get(WalletService::class);
        $pricing = $this->c->get(Pricing::class);
        $before = $request->query('before');
        $next = $request->query('next');
        if (is_string($next) && $next !== '') {
            Session::put('_after_payment', Str::safeNext($next, '/wallet'));
        }

        $buyEnabled = (bool) $this->c->get(\App\Core\Settings\Settings::class)->get('payments.purchase_enabled', false);

        return $this->view($request, 'wallet/index', [
            'title' => t('کیف پول Stars'),
            'balance' => $wallet->balance($user['id']),
            'history' => $wallet->history($user['id'], is_string($before) && ctype_digit($before) ? (int) $before : null),
            'packages' => $pricing->packages(),
            'rate' => $pricing->rate('IRR'),
            'prices' => $pricing->all(),
            'gateways' => $buyEnabled ? $this->c->get(PaymentService::class)->availableGateways() : [],
            'payments' => $this->c->get(PaymentService::class)->recentForUser($user['id']),
            'need' => max(0, (int) $request->query('need', '0')),
            'publishFee' => $this->c->get(\App\Modules\Proposals\ProposalService::class)->publishFee(),
        ]);
    }

    public function buy(Request $request): Response
    {
        $user = $this->user($request);
        if (!(bool) $this->c->get(\App\Core\Settings\Settings::class)->get('payments.purchase_enabled', false)) {
            return $this->redirect('/wallet', t('خرید Stars در حال حاضر فعال نیست.'), 'error');
        }
        $result = $this->c->get(PaymentService::class)->start(
            $user,
            (int) $request->input('package_id', 0),
            (string) $request->input('gateway', '')
        );
        if (!$result['ok']) {
            return $this->redirect('/wallet', $result['error'], 'error');
        }
        return Response::redirect($result['redirect'], 303);
    }
}
