<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

class BrevoTransport extends AbstractTransport
{
    public function __construct(protected string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();

        $sender = $envelope->getSender();
        $payload = [
            'sender' => [
                'name' => $sender->getName() !== '' ? $sender->getName() : $sender->getAddress(),
                'email' => $sender->getAddress(),
            ],
            'to' => array_map(
                static fn (Address $address): array => [
                    'email' => $address->getAddress(),
                    'name' => $address->getName() !== '' ? $address->getName() : $address->getAddress(),
                ],
                $this->getRecipients($email, $envelope)
            ),
            'subject' => (string) $email->getSubject(),
        ];

        if ($html = $email->getHtmlBody()) {
            $payload['htmlContent'] = $html;
        }

        if ($text = $email->getTextBody()) {
            $payload['textContent'] = $text;
        } elseif (! isset($payload['htmlContent'])) {
            $payload['textContent'] = (string) $email->getBody();
        }

        if ($cc = $email->getCc()) {
            $payload['cc'] = array_map(
                static fn (Address $address): array => ['email' => $address->getAddress(), 'name' => $address->getName()],
                $cc
            );
        }

        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = array_map(
                static fn (Address $address): array => ['email' => $address->getAddress(), 'name' => $address->getName()],
                $bcc
            );
        }

        if ($replyTo = $email->getReplyTo()) {
            $first = $replyTo[0];
            $payload['replyTo'] = [
                'email' => $first->getAddress(),
                'name' => $first->getName() !== '' ? $first->getName() : $first->getAddress(),
            ];
        }

        $response = Http::withHeaders([
            'api-key' => $this->apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->timeout(20)->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            throw new TransportException(
                'Request to Brevo API failed. Reason: '.$response->json('message', $response->body()),
                $response->status()
            );
        }

        $messageId = (string) $response->json('messageId', '');
        if ($messageId !== '') {
            $email->getHeaders()->addHeader('X-Brevo-Message-Id', $messageId);
        }
    }

    /**
     * @return list<Address>
     */
    protected function getRecipients(\Symfony\Component\Mime\Email $email, \Symfony\Component\Mailer\Envelope $envelope): array
    {
        return array_values(array_filter(
            $envelope->getRecipients(),
            static fn (Address $address) => ! in_array($address, array_merge($email->getCc(), $email->getBcc()), true)
        ));
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
