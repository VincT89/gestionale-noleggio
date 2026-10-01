@if(session('status'))<div role="status" class="app-surface rounded border p-4">{{ session('status') }}</div>@endif
@if($errors->any())
    <div role="alert" class="rounded border border-red-500 p-4"><p class="font-semibold">Controlla i dati inseriti.</p><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
