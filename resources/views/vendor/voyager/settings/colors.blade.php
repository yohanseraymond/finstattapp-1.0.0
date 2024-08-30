<div id="colors" class="tab-pane fade in @if($group == $active && $active === 'Colors') active @endif">
    <div class="">
        <div class="alert alert-info alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">×</span></button>

            <div class="info-label d-flex">
                <div class="icon voyager-info-circled"></div>
                <span class="ml-2">
                                            Few general notes about generating themes.
                                        </span>
            </div>
            <ul class="mt-05">
                <li>The themes are generated on a remote server. Timings may vary but it might take between 20-40s for a run.</li>
                <li>Regular license holders can generate 5 themes per day.</li>
                <li>If <code>zip</code> extension is available on the server, the theme will be updated automatically.</li>
                <li>If the extension is not available, you will need to upload the archive you'll be getting onto the following directory : <code>public/css/theme</code>.</li>
                <li>When updating your site, remember to backup your <code>public/css/theme</code> folder and restore it after the update.</li>
            </ul>
        </div>
    </div>

    <div class="kind-of-a-form-control">

        <div class="panel-heading setting-row setting-theme_color_code" data-settingkey="theme_color_code">
            <h3 class="panel-title">
                Primary color code
            </h3>
        </div>

        <div class="panel-body no-padding-left-right setting-row setting-theme_color_code" data-settingkey="theme_color_code">
            <div class="col-md-12 no-padding-left-right">
                <input type="text" class="form-control" name="theme_color_code" id="theme_color_code" value="#{{getSetting('colors.theme_color_code') ? getSetting('colors.theme_color_code') : 'cb0c9f'}}">
            </div>
        </div>
        <div class="admin-setting-description">
            <code>
                Theme primary color hex code. EG: #cb0c9f
            </code>
        </div>

    </div>

    <div class="row">

        <div class="kind-of-a-form-control col-lg-6">

            <div class="panel-heading setting-row setting-theme_gradient_from" data-settingkey="theme_gradient_from">
                <h3 class="panel-title">
                    Gradient color start from
                </h3>
            </div>

            <div class="panel-body no-padding-left-right setting-row setting-theme_gradient_from" data-settingkey="theme_gradient_from">
                <div class="col-md-12 no-padding-left-right">
                    <input type="text" class="form-control" name="theme_gradient_from" id="theme_gradient_from" value="#{{getSetting('colors.theme_gradient_from') ? getSetting('colors.theme_gradient_from') : 'cb0c9f'}}">
                </div>
            </div>
            <div class="admin-setting-description">
                <code>
                    Theme's primary gradient - start from, color hex code. EG: #7928CA
                </code>
            </div>

        </div>

        <div class="kind-of-a-form-control col-lg-6">

            <div class="panel-heading setting-row setting-theme_gradient_to" data-settingkey="theme_gradient_to">
                <h3 class="panel-title">
                    Gradient color ends on
                </h3>
            </div>

            <div class="panel-body no-padding-left-right setting-row setting-theme_gradient_to" data-settingkey="theme_gradient_to">
                <div class="col-md-12 no-padding-left-right">
                    <input type="text" class="form-control" name="theme_gradient_to" id="theme_gradient_to" value="#{{getSetting('colors.theme_gradient_to') ? getSetting('colors.theme_gradient_to') : 'cb0c9f'}}">
                </div>
            </div>
            <div class="admin-setting-description">
                <code>
                    Theme's primary gradient - ends on, color hex code. EG: #FF0080
                </code>
            </div>

        </div>


        <div class="kind-of-a-form-control col-lg-12">

            <div class="panel-heading setting-row setting-theme_skip_rtl" data-settingkey="theme_skip_rtl">
                <h3 class="panel-title">
                    Include RTL versions
                </h3>
            </div>

            <div class="panel-body no-padding-left-right setting-row setting-theme_skip_rtl" data-settingkey="theme_skip_rtl">
                <div class="col-md-12 no-padding-left-right">
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="theme_skip_rtl" value="">
                            Generate RTL Versions as well
                        </label>
                    </div>
                </div>
            </div>
            <div class="admin-setting-description">
                <code>
                    Choose if RTL version of the theme should be generated or not. If enabled, theme generation time will increase.
                </code>
            </div>

        </div>

    </div>
</div>
