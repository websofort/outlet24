<div x-data="infiniteScrollStatus('ProductIndex')">
    <template x-if="enabled && isLoadingMore">
        <div class="infinite-scroll-loading text-center py-4">
            <div class="loading" role="status">
            </div>
        </div>
    </template>

    <template x-if="enabled && !hasMoreProducts && parentData?.products?.total > parentData?.queryParams?.perPage">
        <div class="infinite-scroll-end text-center py-4">
            <p class="text-muted">{{ trans('storefront::products.no_more_products') }}</p>
        </div>
    </template>
</div>
