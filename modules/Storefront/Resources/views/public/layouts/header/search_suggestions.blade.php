@if (setting('storefront_most_searched_keywords_enabled') && !empty($mostSearchedKeywords))
    <div class="searched-keywords">
        <label>{{ trans("storefront::layouts.most_searched") }}</label>

        <ul class="list-inline searched-keywords-list">
            @foreach ($mostSearchedKeywords as $mostSearchedKeyword)
                <li>
                    <a href="{{ route('products.index', ['query' => $mostSearchedKeyword]) }}">
                        {{ $mostSearchedKeyword }}{{ !$loop->last ? ',' : '' }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
