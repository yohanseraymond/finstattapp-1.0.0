<div class="{{getSetting('custom-code-ads.sidebar_ad_spot') ? 'mt-3' : 'mt-1'}} pt-3 text-center {{getSetting('custom-code-ads.sidebar_ad_spot') ? 'border-top' : ''}} widgets-footer">
    @foreach(GenericHelper::getFooterPublicPages() as $page)
        <a href="{{route('pages.get',['slug' => $page->slug])}}" target="" class="widgets-footer-link text-muted text-dark-r m-2">{{__($page->short_title ? $page->short_title : $page->title)}}</a>
    @endforeach
</div>
