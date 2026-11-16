@extends('emails.layout')
@section('body')
<p>Hello {{ $user->name }},</p>
<p>Use this code to finish signing in. It expires in {{ $minutes }} minutes.</p>
<p style="font-size:32px;letter-spacing:8px;font-weight:700;margin:20px 0;">{{ $code }}</p>
<p>Never share this code with anyone. KBT RUVIA staff will never ask for it.</p>
@endsection
