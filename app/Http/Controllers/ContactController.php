<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $recipient = config('mail.contact_address') ?: config('mail.from.address');

        Mail::to($recipient)->send(new ContactMessage($validated));

        return back()->with('success', 'Pesan berhasil dikirim. Tim DermaCerdas akan meninjau kontak ini.');
    }
}
