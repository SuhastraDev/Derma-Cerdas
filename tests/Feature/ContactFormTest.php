<?php

namespace Tests\Feature;

use App\Mail\ContactMessage;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    public function test_contact_form_requires_valid_payload(): void
    {
        Mail::fake();

        $this->post(route('contact.store'), [])
            ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

        Mail::assertNothingSent();
    }

    public function test_contact_form_sends_email_and_flashes_success(): void
    {
        Mail::fake();
        config(['mail.contact_address' => 'hello@dermacerdas.local']);

        $this->post(route('contact.store'), [
            'name' => 'Indra',
            'email' => 'indra@example.com',
            'subject' => 'Feedback UI',
            'message' => 'Tampilan halaman user sudah lebih nyaman.',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        Mail::assertSent(ContactMessage::class, function (ContactMessage $mail): bool {
            return $mail->hasTo('hello@dermacerdas.local')
                && $mail->hasReplyTo('indra@example.com')
                && $mail->payload['subject'] === 'Feedback UI';
        });
    }
}
