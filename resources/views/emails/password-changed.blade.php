@extends('emails.layout')
@section('body')
<p>Hello {{ $user->name }},</p>
<p>Your KBT RUVIA BioTech password was changed on {{ now()->format('d M Y, H:i') }} (server time). You were signed out of all devices.</p>
<p>If this was not you, reset your password immediately and contact your administrator.</p>
@endsection
