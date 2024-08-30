<?php

namespace App\Http\Controllers;

use App\Model\Country;
use App\Providers\GenericHelperServiceProvider;
use App\Providers\ListsHelperServiceProvider;
use App\Providers\PostsHelperServiceProvider;
use App\Providers\StreamsServiceProvider;
use Carbon\Carbon;
use Cookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use JavaScript;
use Session;

class ProfileController extends Controller
{
    protected $user;
    protected $hasSub = false;
    protected $isOwner = false;
    protected $isPublic = false;
    protected $viewerHasChatAccess = false;

    public function __construct(Request $request)
    {
        $username = $request->route('username');
        $this->user = PostsHelperServiceProvider::getUserByUsername($username);
    }

    /**
     * Renders the main profile page & first feed posts, if available.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Exception
     */
    public function index(Request $request)
    {
        // Forcing no cache, in order to be able to return from post over
        // profile w/o saving state, and be able to paginate from where we left of
        header('Cache-Control: no-cache, no-store, must-revalidate'); // HTTP 1.1.
        header('Pragma: no-cache'); // HTTP 1.0.
        header('Expires: 0 '); // Proxies.

        // Valid profile sluck checker
        if (! $this->user) {
            abort(404);
        }

        // General access rules
        $this->setAccessRules();
        if (!$this->user->public_profile && !Auth::check()) {
            abort(403,__('Profile access is denied.'));
        }

        // Geoblocking rule
        if($this->isGeoLocationBlocked()){
            abort(403,__('Profile access is denied.'));
        }

        $data['showLoginDialog'] = false;
        $errors = session()->get('errors', app(ViewErrorBag::class));
        if ($errors->getBag('default')->has('email') || $errors->getBag('default')->has('name') || $errors->getBag('default')->has('password')) {
            $data['showLoginDialog'] = true;
        }

        $postsFilter = $request->get('filter') ? $request->get('filter') : false;
        $startPage = PostsHelperServiceProvider::getFeedStartPage(PostsHelperServiceProvider::getPrevPage($request));
        $posts = PostsHelperServiceProvider::getUserPosts($this->user->id, false, $startPage, $postsFilter, $this->hasSub);
        PostsHelperServiceProvider::shouldDeletePaginationCookie($request);
        $posts = $posts->appends($_GET);

        $offer = [];
        if ($this->user->offer && !getSetting('profiles.disable_profile_offers')) {
            $discount30 = 100 - (($this->user->profile_access_price * 100) / $this->user->offer->old_profile_access_price);
            $discount90 = 100 - (($this->user->profile_access_price_3_months * 100) / ($this->user->offer->old_profile_access_price_3_months ? $this->user->offer->old_profile_access_price_3_months : 1));
            $discount182 = 100 - (($this->user->profile_access_price_6_months * 100) / ($this->user->offer->old_profile_access_price_6_months ? $this->user->offer->old_profile_access_price_6_months : 1));
            $discount365 = 100 - (($this->user->profile_access_price_12_months * 100) / ($this->user->offer->old_profile_access_price_12_months ? $this->user->offer->old_profile_access_price_12_months : 1));
            $expiringDate = $this->user->offer->expires_at;
            $currentDate = Carbon::now();
            if ($expiringDate > $currentDate) {
                $offer = [
                    'discountAmount' => [
                        '30' => $discount30,
                        '90' => $discount90,
                        '182' => $discount182,
                        '365' => $discount365,
                    ],
                    'daysRemaining' => $expiringDate->diffInDays($currentDate),
                    'expiresAt' => $expiringDate,
                ];
            }
        }

        $data = array_merge($data,[
            'user' => $this->user,
            'hasSub' => $this->hasSub,
            'posts' => $posts,
            'activeFilter' => $postsFilter,
            'filterTypeCounts' => PostsHelperServiceProvider::getUserMediaTypesCount($this->user->id),
            'offer'=> $offer,
            'viewerHasChatAccess'=> $this->viewerHasChatAccess,
        ]);

        if($postsFilter == 'streams'){
            $streams = StreamsServiceProvider::getPublicStreams(['userId' => $this->user->id, 'status' => 'all']);
            $data['streams'] = $streams;
        }
        $data['hasActiveStream'] = StreamsServiceProvider::getUserInProgressStream(true, $this->user->id) ? true : false;

        $data['recentMedia'] = false;
        if ($this->hasSub || (Auth::check() && Auth::user()->id == $this->user->id) || (getSetting('profiles.allow_users_enabling_open_profiles') && $this->user->open_profile)) {
            $data['recentMedia'] = PostsHelperServiceProvider::getLatestUserAttachments($this->user->id, 'image');
        }

        $additionalAssets = [];
        if(getSetting('profiles.allow_profile_qr_code')){
            $additionalAssets[] = '/libs/easyqrcodejs/dist/easy.qrcode.min.js';
        }
        $data['additionalAssets'] = $additionalAssets;

        $paginatorConfig = [
            'next_page_url' => str_replace(['?page=', '?filter='], ['/posts?page=', '/posts?filter='], $posts->nextPageUrl()),
            'prev_page_url' => str_replace(['?page=', '?filter='], ['/posts?page=', '/posts?filter='], $posts->previousPageUrl()),
            'current_page' => $posts->currentPage(),
            'total' => $posts->total(),
            'per_page' => $posts->perPage(),
            'hasMore' => $posts->hasMorePages(),
        ];

        if($postsFilter == 'streams') {
            $paginatorConfig = [
                'next_page_url' => str_replace(['?page=', '?filter='], ['/streams?page=', '/streams?filter='], $streams->nextPageUrl()),
                'prev_page_url' => str_replace(['?page=', '?filter='], ['/streams?page=', '/streams?filter='], $streams->previousPageUrl()),
                'current_page' => $streams->currentPage(),
                'total' => $streams->total(),
                'per_page' => $streams->perPage(),
                'hasMore' => $streams->hasMorePages(),
            ];
        }

        // Seo description for share urls
        $rawDescription = getSetting('profiles.allow_profile_bio_markdown') && $this->user->bio ? strip_tags(GenericHelperServiceProvider::parseProfileMarkdownBio($this->user->bio)) : $this->user->bio;
        $data['seo_description'] = $rawDescription ? str_replace(array("\n", "\r"), ' ', substr($rawDescription,0, 90)) . (strlen($rawDescription) > 90 ? '...' : '') : null;

        Session::put('lastProfileUrl', route('profile',['username'=> $this->user->username]));

        JavaScript::put([
            'paginatorConfig' => $paginatorConfig,
            'messengerVars' => [
                'bootFullMessenger' => false,
            ],
            'initialPostIDs' => $posts->pluck('id')->toArray(),
            'profileVars' => [
                'user_id' =>  $this->user->id,
            ],
            'showLoginDialog' => $data['showLoginDialog'],
            'postsFilter' => $postsFilter
        ]);

        return view('pages.profile', $data);
    }

    /**
     * Fetches user posts, to be paginated into the profile page.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserPosts(Request $request)
    {
        $this->setAccessRules();
        $postsFilter = $request->get('filter') ? $request->get('filter') : false;

        return response()->json([
            'success' => true,
            'data' => PostsHelperServiceProvider::getUserPosts($this->user->id, true, false, $postsFilter, $this->hasSub),
        ]);
    }

    /**
     * Fetches paginated user (public) streams
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserStreams(Request $request){
        $this->setAccessRules();
        return response()->json([
            'success'=>true,
            'data'=>StreamsServiceProvider::getPublicStreams(['encodePostsToHtml'=>true, 'status' => 'all','showUsername'=>false]),
        ]);
    }

    /**
     * Checks if current logged user (if any) has rights to view the profile media.
     */
    protected function setAccessRules()
    {
        $viewerUser = null;
        if (Auth::check()) {
            $viewerUser = Auth::user();
        }
        if ($viewerUser) {
            $this->hasSub = PostsHelperServiceProvider::hasActiveSub($viewerUser->id, $this->user->id);
            if ($viewerUser->id === $this->user->id) {
                $this->hasSub = true;
                $this->isOwner = true;
                $this->viewerHasChatAccess = true;
            }
            if(!$this->user->paid_profile && ListsHelperServiceProvider::loggedUserIsFollowingUser($this->user->id)){
                $this->hasSub = true;
                $this->viewerHasChatAccess = true;
            }
            if((getSetting('profiles.allow_users_enabling_open_profiles') && $this->user->open_profile) && ListsHelperServiceProvider::loggedUserIsFollowingUser($this->user->id)){
                $this->hasSub = true;
                $this->viewerHasChatAccess = true;
            }
            if($viewerUser->role_id === 1){
                $this->hasSub = true;
                $this->isOwner = true;
                $this->viewerHasChatAccess = true;
            }
        }
    }

    protected function isGeoLocationBlocked(){
        if(Auth::check() && Auth::user()->role_id === 1){
            return false;
        }
        if(getSetting('security.allow_geo_blocking')){
            if($this->user->enable_geoblocking){
                if(isset($this->user->settings['geoblocked_countries'])){
                    $countries = json_decode($this->user->settings['geoblocked_countries']);
                    $blockedCountries = Country::whereIn('name',$countries)->get();
                    $client = new \GuzzleHttp\Client();
                    $apiRequest = $client->get('https://ipgeolocation.abstractapi.com/v1/?api_key='.getSetting('security.abstract_api_key').'&ip_address=' . $_SERVER['REMOTE_ADDR']);
                    $apiData = json_decode($apiRequest->getBody()->getContents());
                    foreach($blockedCountries as $country){
                        if($country->country_code == $apiData->country_code){
                            if(!(Auth::check() && Auth::user()->id === $this->user->id)){
                                return true;
                            }
                        }
                    }

                }
            }
        }
        return false;
    }

}
