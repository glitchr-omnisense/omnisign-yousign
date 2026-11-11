<?php

namespace Omnisign\Yousign\Action;

use Omnisign\Model\Event;
use Omnisign\Model\Notification;
use Omnisign\Request\Notify;
use Omnisign\Request\Request;
use Omnisign\Yousign\Data;

/** A webhook's call: X-Yousign-Signature-256 checked, event_name read. */
final class NotifyAction extends AbstractAction
{
    private const EVENTS = [
        'signature_request.activated' => Event::SENT,
        'signature_request.done' => Event::COMPLETED,
        'signature_request.declined' => Event::DECLINED,
        'signature_request.rejected' => Event::DECLINED,
        'signature_request.expired' => Event::EXPIRED,
        'signature_request.canceled' => Event::CANCELED,
        'signer.link_opened' => Event::OPENED,
        'signer.done' => Event::SIGNED,
        'signer.declined' => Event::DECLINED,
    ];

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $payload = $this->api->verify($request->body, $request->header('X-Yousign-Signature-256'));
        $read = Data::of($payload);
        $type = $read->text('event_name');
        $data = Data::of((array) ($payload['data'] ?? []));
        $time = $read->textOrNull('event_time');
        $request->setResult(new Notification(
            self::EVENTS[$type] ?? Event::OTHER,
            $type,
            $data->data('signature_request')->textOrNull('id'),
            $data->data('signer')->textOrNull('id'),
            null !== $time && ctype_digit($time) ? new \DateTimeImmutable('@'.$time) : null,
            $read->textOrNull('event_id'),
            $payload,
        ));
    }
}
