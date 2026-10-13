{{-- /crm/customers (S2-09, §1.2). The screen itself is resources/views/crm/partials/screen.blade.php;
     this file names which record type it is, and Livewire gets its one root element. --}}
@include('crm.partials.screen', ['entity' => 'customers'])
