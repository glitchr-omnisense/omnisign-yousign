<?php

namespace Omnisign\Yousign\Action;

use Omnisign\Exception\InvalidConfigException;
use Omnisign\Model\Field;
use Omnisign\Model\FieldType;
use Omnisign\Model\Level;
use Omnisign\Model\SignerState;
use Omnisign\Model\SignerStatus;
use Omnisign\Model\StatementMode;
use Omnisign\Request\Create;
use Omnisign\Request\Request;
use Omnisign\Yousign\Data;

/**
 * A signature request in four calls: the request (POST /signature_requests),
 * each document (POST .../documents, multipart), each signer with their
 * fields (POST .../signers) - a draft, sent by Send (activate).
 *
 * A statement (Field::$statement) typed by the signer is a text field: the
 * statement as its instruction (no length stated), the field's label as its
 * question (255 characters at most), max_length the statement's length and
 * a fifth more (32767 at most). An acknowledged one is a read-only text on
 * the document (POST .../documents/{id}/fields, 5000 characters at most),
 * placed before the signers. A TEXT field's max_length is its $maxLength,
 * else 255.
 */
final class CreateAction extends AbstractAction
{
    private const LEVELS = [
        'simple' => 'electronic_signature',
        'advanced' => 'advanced_electronic_signature',
        'qualified' => 'qualified_electronic_signature',
    ];
    private const AUTHENTICATIONS = ['email' => 'otp_email', 'sms' => 'otp_sms', 'none' => 'no_otp'];

    public function supports(Request $request): bool
    {
        return $request instanceof Create;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Create);
        $envelope = $request->envelope;
        if (!$envelope->documents || !$envelope->signers) {
            throw new InvalidConfigException('A Yousign signature request needs a document and a signer at least.');
        }
        $created = $this->api->json('POST', '/signature_requests', array_filter([
            'name' => mb_substr($envelope->title, 0, 128),
            'delivery_mode' => $envelope->embedded ? 'none' : 'email',
            'ordered_signers' => $envelope->ordered,
            'expiration_date' => $envelope->expiresAt?->format('Y-m-d'),
            'timezone' => $envelope->expiresAt?->getTimezone()->getName() === 'UTC' ? null : $envelope->expiresAt?->getTimezone()->getName(),
            'external_id' => $envelope->key,
        ], static fn ($v) => null !== $v));
        $id = Data::of($created)->text('id');

        $documents = [];
        foreach ($envelope->documents as $document) {
            $uploaded = $this->api->upload("/signature_requests/$id/documents", $document->file->content, $document->file->filename, $document->file->mimeType, $document->signable);
            $documents[$document->key] = Data::of($uploaded)->text('id');
        }

        foreach ($envelope->fields as $field) {
            if (StatementMode::ACKNOWLEDGED === $field->mode()) {
                $this->api->json('POST', "/signature_requests/$id/documents/".($documents[$field->document] ?? throw new InvalidConfigException(\sprintf('A field points at no document "%s".', $field->document))).'/fields', array_filter([
                    'type' => 'read_only_text', 'page' => $field->page, 'x' => $field->x, 'y' => $field->y, 'width' => $field->width, 'height' => $field->height, 'text' => $field->statement,
                ], static fn ($v) => null !== $v));
            }
        }

        $signers = $envelope->signers;
        if ($envelope->ordered) {
            usort($signers, static fn ($a, $b) => $a->order <=> $b->order);
        }
        $states = [];
        foreach ($signers as $signer) {
            [$first, $last] = $signer->names();
            $added = $this->api->json('POST', "/signature_requests/$id/signers", array_filter([
                'info' => array_filter(['first_name' => $first, 'last_name' => $last, 'email' => $signer->email, 'phone_number' => $signer->phone, 'locale' => $signer->locale]),
                'signature_level' => self::LEVELS[$envelope->level->value],
                'signature_authentication_mode' => null !== $signer->authentication ? (self::AUTHENTICATIONS[$signer->authentication] ?? throw new InvalidConfigException(\sprintf('Yousign authenticates a signer by email, sms or none, not "%s".', $signer->authentication))) : (Level::SIMPLE === $envelope->level ? 'otp_email' : null),
                'fields' => array_map(fn (Field $f) => $this->field($f, $documents), array_values(array_filter($envelope->fieldsOf($signer->key), static fn (Field $f) => StatementMode::ACKNOWLEDGED !== $f->mode()))),
                'redirect_urls' => null !== $envelope->redirectUrl ? ['success' => $envelope->redirectUrl] : null,
            ], static fn ($v) => null !== $v));
            $states[$signer->key] = new SignerState($signer->key, Data::of($added)->text('id'), SignerStatus::WAITING);
        }

        $request->setResult(self::read($envelope->with(states: $states, documentReferences: $documents), $created));
    }

    /**
     * @param array<string, string> $documents
     *
     * @return array<string, mixed>
     */
    private function field(Field $field, array $documents): array
    {
        $base = ['document_id' => $documents[$field->document] ?? throw new InvalidConfigException(\sprintf('A field points at no document "%s".', $field->document)), 'page' => $field->page, 'x' => $field->x, 'y' => $field->y];

        return match ($field->type) {
            FieldType::SIGNATURE => $base + ['type' => 'signature'] + array_filter(['width' => $field->width, 'height' => $field->height]),
            FieldType::MENTION => $base + ['type' => 'mention', 'mention' => $field->label ?? 'Lu et approuvé'],
            FieldType::TEXT => null !== $field->statement
                ? $base + ['type' => 'text', 'question' => mb_substr($field->label ?? 'Mention', 0, 255), 'instruction' => $field->statement, 'max_length' => min(32767, (int) ceil(mb_strlen($field->statement) * 1.2)), 'optional' => false] + array_filter(['width' => $field->width, 'height' => $field->height])
                : $base + ['type' => 'text', 'question' => $field->label ?? 'Text', 'max_length' => max(1, min(32767, $field->maxLength ?? 255)), 'optional' => false],
            default => throw new InvalidConfigException(\sprintf('Yousign places no "%s" field with a signer: signature, mention and text.', $field->type->value)),
        };
    }
}
