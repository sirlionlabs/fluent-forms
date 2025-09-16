<?php

namespace FluentForms\Mailers;

use MailerSend\Exceptions\MailerSendException;
use MailerSend\Helpers\Builder\EmailParams;
use MailerSend\Helpers\Builder\Recipient;
use MailerSend\MailerSend as MailerSendSdk;
use Psr\Http\Message\ResponseInterface as Response;

trait MailerSend
{
    public function send(Response $response): Response
    {
        $payload = $this->parsedBody;
        
        // CHECK IF PAYLOAD IS MISSING ANY REQUIRED EmailParams()

        if ($this->isValid()) {
            try {
                $mailersend = new MailerSendSdk(['api_key' => $_ENV['MAILERSEND_API_KEY']]);

                $recipients = [
                    new Recipient($_ENV['MAIL_TO'] ?? null, $_ENV['MAIL_TO_NAME'] ?? null ),
                ];

                $emailParams = (new EmailParams())
                    ->setFrom($_ENV['MAIL_FROM'] ?? null )
                    ->setFromName($_ENV['APP_NAME'] ?? 'Contact Form')
                    ->setRecipients($recipients)
                    ->setSubject('Contact '.$_ENV['APP_NAME']. ' from '. $payload['name'] )
                    // ->setHtml('This is the HTML content')
                    ->setText($payload['message'])
                    ->setReplyTo($payload['email'])
                    ->setReplyToName($payload['name']);

                    if ( $_ENV['APP_ENV'] === 'production' ) {
                        $mailersend->email->send($emailParams);
                    }
                    
                    $this->success();
            }
            catch(MailerSendException $e) {
                // log... 

                $this->reject();
                $errorMessage = $_ENV['APP_DEBUG'] ? $e->getMessage() : $e->getCode().': '.$this->getErrorMessage() ;
                $this->addError('mailer', $errorMessage );
            }
        }

        # JSON
        if ( isset($_POST['ajax']) ) {
            if ( $this->successful() ):
                $payload['successful'] = $this->getSuccessMessage();
                $response->getBody()->write(json_encode($payload));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200); 
            else:
                $response->getBody()->write(json_encode($this->getErrors()));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            endif;
        }

        # PHP
        if ( $this->successful() ) {
            return $response->withHeader('Location', $_SERVER['PHP_SELF'].'?success')->withStatus(301);
        }
        if ( $this->rejected() ) {
            return $response->withHeader('Location', $_SERVER['PHP_SELF'].'?rejected')->withStatus(301);
        }
        if ( !$this->isValid() ) {
            return $response->withHeader('Location', $_SERVER['PHP_SELF'])->withStatus(400);
        }
        return $response;
    }
} 