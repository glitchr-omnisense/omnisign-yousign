<?php

namespace Omnisign\Yousign\Action;

use Omnisign\Action\ActionInterface;
use Omnisign\Action\ApiAwareInterface;
use Omnisign\Action\ApiAwareTrait;
use Omnisign\Model\Envelope;
use Omnisign\Model\SignerState;
use Omnisign\Model\Status;
use Omnisign\Yousign\Api;
use Omnisign\Yousign\Data;

/** An action on Yousign's API v3. */
abstract class AbstractAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /**
     * The envelope as a signature request answered it: its status, and its
     * signers' - found back by the order they were added in.
     *
     * @param array<string, mixed> $request
     */
    protected static function read(Envelope $envelope, array $request): Envelope
    {
        $states = $envelope->states;
        $byReference = [];
        foreach ($states as $key => $state) {
            if (null !== $state->reference) {
                $byReference[$state->reference] = $key;
            }
        }
        $answer = Data::of($request);
        foreach ((array) ($request['signers'] ?? []) as $signer) {
            $signer = Data::of($signer);
            $id = $signer->get('id') ?? '';
            $key = \is_string($id) || \is_int($id) ? $byReference[$id] ?? null : null;
            if (null === $key) {
                continue;
            }
            $old = $states[$key];
            $status = Api::SIGNER_STATUSES[$signer->text('status')] ?? $old->status;
            $states[$key] = new SignerState($key, $old->reference, $status, $old->signedAt, $signer->textOrNull('signature_link') ?? $old->link, $signer->moment('signature_link_expiration_date') ?? $old->linkExpiresAt, $old->reason);
        }

        return $envelope->with(
            reference: $answer->textOrNull('id') ?? (string) $envelope->reference,
            status: Api::STATUSES[$answer->text('status')] ?? Status::UNKNOWN,
            states: $states,
            completedAt: $answer->moment('completed_at'),
            data: $request,
        );
    }
}
