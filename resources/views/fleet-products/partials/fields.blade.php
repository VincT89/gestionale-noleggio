@php
    $currentProduct = $product ?? null;
    $productValue = function ($key) use ($currentProduct) {
        $value = old($key, $currentProduct?->{$key} ?? '');
        return is_scalar($value) ? (string) $value : '';
    };
@endphp
<div><label for="product-name" class="block text-sm font-medium mb-1">Nome prodotto</label><input id="product-name" name="name" value="{{ $productValue('name') }}" maxlength="120" required class="app-field w-full rounded border" @if($errors->has('name')) aria-invalid="true" @endif></div>
<div><label for="product-description" class="block text-sm font-medium mb-1">Descrizione interna (facoltativa)</label><textarea id="product-description" name="description" rows="3" maxlength="2000" class="app-field w-full rounded border" aria-describedby="description-help">{{ $productValue('description') }}</textarea><p id="description-help" class="text-sm mt-1">Serve a riconoscere quali auto associare al prodotto. Sul sito restano visibili le condizioni della singola offerta selezionata.</p></div>
