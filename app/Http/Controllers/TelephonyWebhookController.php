<?php

namespace App\Http\Controllers;

use App\Services\Telephony\TelephonyGateway;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelephonyWebhookController extends Controller
{
    public function inbound(Request $request, string $provider, TelephonyGateway $gateway): Response
    {
        if (! in_array($provider, ['twilio', 'telnyx', 'sip'], true)) {
            return response()->json(['error' => 'Unsupported provider'], 400);
        }

        $result = $gateway->handleInbound($request, $provider);

        if (($result['action'] ?? null) === 'twiml') {
            return response($result['content'], 200, ['Content-Type' => 'text/xml']);
        }

        return response()->json($result);
    }

    public function status(Request $request, string $provider, TelephonyGateway $gateway): Response
    {
        if (! in_array($provider, ['twilio', 'telnyx', 'sip'], true)) {
            return response()->json(['error' => 'Unsupported provider'], 400);
        }

        $gateway->handleStatusCallback($request, $provider);

        return response()->json(['status' => 'acknowledged']);
    }
}
