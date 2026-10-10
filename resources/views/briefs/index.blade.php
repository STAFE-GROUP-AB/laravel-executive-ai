@use('App\Briefs\Render\BriefLocale')
@use('App\Briefs\Render\BriefTime')
@use('App\Briefs\Render\ItemPresentation')
@use('App\Support\Health\BriefState')
@extends('layouts.app')

@section('title', 'Briefs')

@section('content')
    <h1 class="text-2xl font-semibold">Briefs</h1>
    <p class="mt-2 max-w-readable text-md text-secondary">Every frozen version of your Executive Brief, newest first. Each version is immutable; a correction is a new version.</p>

    @if($briefs->isEmpty())
        <x-empty-state title="No briefs yet" class="mt-6">
            Versions appear here as soon as the core freezes one. Your first real Executive Brief follows AI setup on your Mac.
        </x-empty-state>
    @else
        <div class="mt-6 overflow-x-auto rounded-lg bg-surface shadow-1">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <caption class="sr-only">Brief versions</caption>
                <thead class="border-b border-default text-xs font-medium tracking-wide text-muted uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">Subject</th>
                        <th scope="col" class="px-4 py-3">Version</th>
                        <th scope="col" class="px-4 py-3">Generated</th>
                        <th scope="col" class="px-4 py-3">Delivery</th>
                        <th scope="col" class="px-4 py-3">State</th>
                        <th scope="col" class="px-4 py-3">Document</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-default">
                    @foreach($briefs as $brief)
                        @php
                            $generatedAt = BriefTime::rfc3339($brief->generated_at, $brief->utc_offset);
                            $locale = BriefLocale::resolve($brief->language);
                            $state = $brief->isStaleAt($now) ? BriefState::Stale : BriefState::Fresh;
                        @endphp
                        <tr data-brief-row data-brief-id="{{ $brief->id }}" data-brief-document-id="{{ $brief->document_id }}" data-brief-version="{{ $brief->version }}" data-brief-generated-at="{{ $generatedAt }}" data-delivery-state="{{ $brief->delivery_state->value }}">
                            <td class="min-w-[18rem] px-4 py-3">
                                <a href="{{ route('briefs.show', $brief) }}" class="inline-flex min-h-touch items-center font-medium text-accent" lang="{{ $brief->language }}">{{ $brief->subject }}</a>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">v{{ $brief->version }}</td>
                            <td class="px-4 py-3 whitespace-nowrap" lang="{{ $brief->language }}"><time datetime="{{ $generatedAt }}">{{ BriefLocale::dateTime($generatedAt, $locale) }}</time></td>
                            <td class="px-4 py-3"><x-badge :tone="ItemPresentation::deliveryStateTone($brief->delivery_state->value)">{{ ItemPresentation::deliveryStateLabel($brief->delivery_state->value) }}</x-badge></td>
                            <td class="px-4 py-3"><x-brief-state-badge :state="$state" /></td>
                            <td class="px-4 py-3 font-mono text-xs text-muted">{{ $brief->document_id }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
