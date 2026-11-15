<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$baseUrl">
Krishi AI
</x-mail::header>
</x-slot:header>

# Hello,

We received a request to reset your Krishi AI Farmer Account password.

Click the button below to create a new password.

<x-mail::button :url="$resetUrl" color="success">
Reset Password
</x-mail::button>

This link will expire in {{ $expiresIn }} minutes.

If you did not request a password reset, you can ignore this email.

Thank you,<br>
Krishi AI Team

<x-slot:subcopy>
If you cannot click the Reset Password button, copy and paste this URL into your browser:
[{{ $resetUrl }}]({{ $resetUrl }})
</x-slot:subcopy>

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} Krishi AI. All rights reserved.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
