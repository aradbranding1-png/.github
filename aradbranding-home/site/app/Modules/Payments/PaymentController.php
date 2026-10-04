<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Env;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;

final class PaymentController extends Controller
{
    /** GET /payments/callback/{gateway} — the provider sends the user back here. */
    public function callback(Request $request): Response
    {
        $uid = $this->c->get(PaymentService::class)->callback((string) $request->param('gateway'), $request->all());
        if ($uid === null) {
            throw new HttpException(404);
        }
        return Response::redirect('/payments/' . $uid, 303)->withHeader('Cache-Control', 'no-store');
    }

    /** GET /payments/{uid} — result page for the owner of the payment. */
    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $payment = $this->c->get(PaymentService::class)->find((string) $request->param('uid'), $user['id']);
        if ($payment === null) {
            throw new HttpException(404);
        }
        $after = null;
        if ((int) $payment['status'] === PaymentService::CREDITED) {
            $after = Session::get('_after_payment');
            Session::forget('_after_payment');
        }
        return $this->view($request, 'payments/show', [
            'title' => t('نتیجه پرداخت'),
            'payment' => $payment,
            'after' => is_string($after) ? $after : null,
        ]);
    }

    /** GET /payments/test — simulated gateway page (PAYMENT_TEST_GATEWAY=true only). */
    public function testGateway(Request $request): Response
    {
        if (!Env::get('PAYMENT_TEST_GATEWAY', false)) {
            throw new HttpException(404);
        }
        $cb = (string) $request->query('cb', '');
        $appUrl = rtrim((string) Env::get('APP_URL', ''), '/');
        if (!str_starts_with($cb, $appUrl . '/payments/callback/')) {
            throw new HttpException(400);
        }
        return $this->view($request, 'payments/test', [
            'title' => t('درگاه آزمایشی'),
            'authority' => (string) $request->query('authority', ''),
            'cb' => $cb,
        ]);
    }
}
