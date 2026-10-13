{{--
    Component-shaped wrapper around the S1-04 app shell, for a **full-page
    Livewire component**.

    Livewire 3's #[Layout] renders its layout as an anonymous Blade component
    and hands it the page as `$slot` (SupportPageComponents), while
    `layouts/app.blade.php` is a @yield layout the server-rendered pages
    @extends. This file is the three lines that join the two, so there is one
    app shell rather than a second copy of the navigation, the header and the
    safe-area handling that would then have to be kept in step with it.

    #[Title] arrives as `$title`. It is only declared when it has content: an
    empty @section would still satisfy @hasSection in the shell and render a
    stray separator before the application name.
--}}
@extends('layouts.app')

@if(($title ?? '') !== '')
    @section('title', $title)
@endif

@section('content')
    {{ $slot }}
@endsection
