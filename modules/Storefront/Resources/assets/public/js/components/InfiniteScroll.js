export default (parentComponent = 'ProductIndex') => ({
    isLoadingMore: false,
    hasMoreProducts: true,
    threshold: 200,
    scrollHandler: null,
    resizeHandler: null,
    parentData: null,
    enabled: false,
    _originalFetchProducts: null,
    _intercepted: false,
    _maxPrefetch: 5,

    init() {
        this.parentData = this.getParentData(parentComponent);
        if (!this.parentData) { console.error('Parent component not found'); return; }

        this.enabled = window.FleetCart?.data?.initialInfiniteScroll || false;
        if (!this.enabled) return;

        this.interceptFetchProducts();
        this.normalizePageOnInit();

        this.$nextTick(() => {
            this.initScrollListener();
            this.queueFill();
        });
    },

    getParentData() {
        let el = this.$el.parentElement;
        while (el) {
            const alpineData = Alpine.$data(el);
            if (alpineData && typeof alpineData.fetchProducts === 'function') return alpineData;
            el = el.parentElement;
        }
        return null;
    },

    interceptFetchProducts() {
        if (this._intercepted) return;

        this._originalFetchProducts = this.parentData.fetchProducts.bind(this.parentData);

        this.parentData.fetchProducts = async (options = { updateAttributeFilters: true, forceReset: false }) => {
            const page = Number(this.parentData.queryParams?.page || 1);
            const shouldReset = options.forceReset || options.updateAttributeFilters || page <= 1;
            if (shouldReset) this.reset();

            await this._originalFetchProducts(options);

            await this.$nextTick();

            this.hasMoreProducts = (this.parentData.currentPage || 1) < this.getTotalPages();

            this.queueFill();
        };

        this._intercepted = true;
    },

    normalizePageOnInit() {
        const qp = this.parentData.queryParams || {};
        const current = Number(qp.page || 1);

        if (current > 1) {
            this.parentData.currentPage = 1;
            this.parentData.queryParams.page = 1;

            try {
                const url = new URL(window.location.href);
                if (url.searchParams.has('page')) {
                    url.searchParams.set('page', '1');
                    window.history.replaceState({}, '', url.toString());
                }
            } catch { console.error('Error updating URL'); }

            this.reset();
            this.parentData.fetchProducts({ updateAttributeFilters: true, forceReset: true });
        }
    },


    initScrollListener() {
        this.scrollHandler = () => {
            if (this.isLoadingMore || !this.hasMoreProducts || !this.enabled) return;

            const productsContainer = document.querySelector('.search-result-middle');
            if (!productsContainer) return;

            const containerRect = productsContainer.getBoundingClientRect();
            const viewportHeight = window.innerHeight;

            const products = productsContainer.querySelectorAll('[wire\\:key], [x-data]');
            const lastProduct = products[products.length - 1];

            if (!lastProduct) return;

            const lastProductRect = lastProduct.getBoundingClientRect();

            if (lastProductRect.bottom <= viewportHeight + this.threshold) {
                this.loadMore();
            }
        };

        this.resizeHandler = () => this.triggerCheck();

        window.addEventListener('scroll', this.scrollHandler, { passive: true });
        window.addEventListener('resize', this.resizeHandler, { passive: true });
    },

    async queueFill() {
        await this.waitForParentIdle();

        await this.$nextTick();

        const totalPages = this.getTotalPages();
        const currentPage = Number(this.parentData.currentPage || 1);
        this.hasMoreProducts = currentPage < totalPages;

        this.triggerCheck();

        if (this.hasMoreProducts) {
            await this.fillIfShort();
        }
    },

    triggerCheck() {
        this.scrollHandler && this.scrollHandler();
        requestAnimationFrame(() => this.scrollHandler && this.scrollHandler());
    },

    async waitForParentIdle(timeoutMs = 3000) {
        const start = Date.now();
        while (this.parentData?.fetchingProducts) {
            if (Date.now() - start > timeoutMs) break;
            await new Promise(r => setTimeout(r, 16));
        }
    },

    async fillIfShort() {
        let attempts = 0;
        const productsContainer = document.querySelector('.search-result-middle');
        if (!productsContainer) return;

        while (
            attempts < this._maxPrefetch &&
            this.hasMoreProducts &&
            !this.isLoadingMore &&
            (() => {
                const products = productsContainer.querySelectorAll('[wire\\:key], [x-data]');
                const lastProduct = products[products.length - 1];
                if (!lastProduct) return false;
                return lastProduct.getBoundingClientRect().bottom <= window.innerHeight + this.threshold;
            })()
            ) {
            await this.loadMore();
            await new Promise(r => requestAnimationFrame(r));
            attempts++;
        }
    },

    async loadMore() {
        if (!this.enabled || !this.hasMoreProducts) return;
        if (!this.canLoadMore()) {
            this.hasMoreProducts = false;
            return;
        }

        this.isLoadingMore = true;
        const nextPage = (this.parentData.currentPage || 1) + 1;

        try {
            const response = await axios.get(`/products`, {
                params: { ...this.parentData.queryParams, page: nextPage },
            });

            this.parentData.products.data = [
                ...this.parentData.products.data,
                ...response.data.products.data,
            ];

            this.parentData.products.total = response.data.products.total;
            this.parentData.products.from = this.parentData.products.data.length ? 1 : 0;
            this.parentData.products.to = this.parentData.products.data.length;

            this.parentData.currentPage = nextPage;
            this.parentData.queryParams.page = nextPage;

            const totalPages = this.getTotalPages();
            this.hasMoreProducts = nextPage < totalPages;
        } catch (error) {
            console.error('Error loading more products:', error);
            if (error?.response?.data?.message) notify(error.response.data.message);
        } finally {
            this.isLoadingMore = false;
        }
    },

    canLoadMore() {
        return (this.parentData.currentPage || 1) < this.getTotalPages();
    },

    getTotalPages() {
        const total = Number(this.parentData?.products?.total ?? 0);
        const perPage = Number(this.parentData?.queryParams?.perPage ?? 1) || 1;
        return Math.max(1, Math.ceil(total / perPage));
    },

    reset() {
        this.hasMoreProducts = true;
        this.isLoadingMore = false;
    },

    destroy() {
        if (this.scrollHandler) window.removeEventListener('scroll', this.scrollHandler);
        if (this.resizeHandler) window.removeEventListener('resize', this.resizeHandler);
    },
});
