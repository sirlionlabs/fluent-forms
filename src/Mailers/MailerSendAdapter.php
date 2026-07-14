<?php

namespace FluentForms\Mailers;

use FluentForms\Contracts\Mailer;
use MailerSend\Exceptions\MailerSendException;
use MailerSend\Helpers\Builder\EmailParams;
use MailerSend\Helpers\Builder\Recipient;
use MailerSend\MailerSend as MailerSendSdk;
use Psr\Http\Message\ResponseInterface as Response;
use Closure;
use MailerSend\Exceptions\MailerSendValidationException;
use Psr\Log\LoggerInterface;

class MailerSendAdapter implements Mailer
{
    /** @var Recipeint[] */
    private array $to;

    public function __construct(
        private string $apiKey, 
        Recipient|array $to,
        private Recipient $from,
        private string|Closure $subject,
        private string|Closure $body,
        private Recipient|Closure|null $replyTo = null,
        private bool $debug = false,
        private ?LoggerInterface $logger = null,
    ){
        $this->to = is_array($to) ? $to : [$to];

        if (empty($this->to)) {
            throw new \InvalidArgumentException('At least one recipeint is required');
        }
    }

    public function send(array $data): bool
    {
        $from    = $this->from->toArray();
        $subject = $this->resolveString($this->subject, $data);
        $body    = $this->resolveString($this->body, $data);
        $replyTo = $this->resolveRecipient($this->replyTo, $data);

        if ($this->debug){
            $this->log($from, $subject, $body, $replyTo);
            return true;
        }

        $mailersend = new MailerSendSdk(['api_key' => $this->apiKey]);

        $emailParams = (new EmailParams())
            ->setFrom($from['email'])
            ->setFromName($from['name'])
            ->setRecipients($this->to)
            ->setSubject($this->resolveString($this->subject, $data))
            // ->setHtml('This is the HTML content')
            ->setText($this->resolveString($this->body, $data));

        if ($replyTo) {
            $replyToArray = $replyTo->toArray();
            $emailParams->setReplyTo($replyToArray['email']);
            $emailParams->setReplyToName($replyToArray['name']);
        }

        $mailersend->email->send($emailParams);

        return true;
    }

    private function log(array $from, string $subject, string $body, ?Recipient $replyTo): void
    {
        $headers = [
            'To'       => implode(', ', array_map(fn(Recipient $r) => $r->toArray()['email'], $this->to)),
            'From'     => sprintf('%s <%s>', $from['name'], $from['email']),
            'Subject'  => $subject,
            'Reply-To' => $replyTo?->toArray()['email'] ?? 'none',
        ];

        $headerLines = implode("\n", array_map(
            fn($key, $value) => "{$key}: {$value}",
            array_keys($headers),
            $headers
        ));

        $message = sprintf(
            "[MailerSendAdapter:debug]\n%s\n\n%s",
            $headerLines,
            $body
        );

        $this->logger
            ? $this->logger->info($message)
            : error_log($message);
    }

    private function resolveString(string|Closure|null $value, array $data): ?string
    {
        return match (true) {
            $value instanceof Closure => $value($data),
            default => $value,
        };
    }

    private function resolveRecipient(Recipient|Closure|null $value, array $data): ?Recipient
    {
        return $value instanceof Closure ? $value($data) : $value;
    }
} 