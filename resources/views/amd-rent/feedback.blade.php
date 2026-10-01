@if(session('status'))<p class="amr-notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="amr-notice" role="alert"><strong>Controlla i dati.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
