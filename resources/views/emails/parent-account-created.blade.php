<x-mail::message>
# Welcome to KidSecure

Hi {{ $parentName }},

An account has been created for you to track **{{ $studentName }}'s** school attendance in real time.

**Email:** {{ $email }}
**Temporary Password:** {{ $password }}

Please log in using the KidSecure mobile app and change your password after your first login.

<x-mail::button :url="'https://your-app-store-link'">
Download the App
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>