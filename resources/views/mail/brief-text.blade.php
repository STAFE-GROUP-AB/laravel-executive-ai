{{--
    Executive Brief — text/plain alternative part (S1-03, blueprint §5.5).

    This view produces plain text, not HTML, so values are printed raw with {!! !!}: HTML-escaping
    them would put "&quot;" and "&amp;" into a text/plain body. The output is only ever served as
    text/plain with X-Content-Type-Options: nosniff (BriefPreviewController) or as the text part of
    the mail. The HTML part, resources/views/mail/brief.blade.php, never uses {!! !!} (T-22).
    PlaintextBriefRenderer::normalise() wraps long lines and collapses blank lines afterwards.
--}}
{!! mb_strtoupper(__('brief.brand')) !!} · {!! $version_label !!}
{!! $subject !!}

{!! __('brief.generated_at') !!}: {!! $generated_at_label !!}
{!! __('brief.period') !!}: {!! $period_label !!}

@foreach($summary_paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@if($items === [])
{!! __('brief.nothing_new_title') !!}
{!! __('brief.nothing_new_body') !!}

@else
{!! __('brief.items') !!} ({!! count($items) !!})
{!! str_repeat('-', mb_strlen(__('brief.items').' ('.count($items).')')) !!}

@foreach($items as $item)
{!! $item['number'] !!}. [{!! $item['kind_label'] !!} · {!! $item['priority_label'] !!}] {!! $item['title'] !!}

{!! $item['body'] !!}
@if($item['due_at_label'] !== null)

{!! __('brief.due') !!}: {!! $item['due_at_label'] !!}
@endif
@if($item['sources'] !== [])

{!! trans_choice('brief.sources', count($item['sources'])) !!}:
@foreach($item['sources'] as $source)
- {!! $source['type_label'] !!}@if($source['account_label'] !== null) · {!! $source['account_label'] !!}@endif @if($source['title'] !== null)· {!! $source['title'] !!} @endif· {!! $source['uri'] !!}
@endforeach
@endif
@if($item['uncertainty'] !== null)

{!! __('brief.uncertainty') !!} ({!! $item['uncertainty']['confidence_label'] !!}): {!! $item['uncertainty']['reason'] !!}
@endif
@foreach($item['approvals'] as $approval)

{!! __('brief.approval_required') !!}: {!! $approval['href'] !!}
@endforeach

@endforeach
@endif
--
{!! __('brief.open_in_app') !!}: {!! $open_url !!}
{!! __('brief.privacy_mode') !!}: {!! $privacy_mode_label !!} ({!! $privacy_mode !!})
{!! __('brief.brief_id') !!} {!! $brief_id !!} · {!! $version_label !!} · {!! $content_hash_short !!}
{!! __('brief.footer_note', ['app' => $app_name]) !!}
