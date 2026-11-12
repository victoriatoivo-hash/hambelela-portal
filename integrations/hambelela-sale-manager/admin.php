<?php
namespace Hambelela\Sales;
defined('ABSPATH') || exit;

add_action('admin_menu', static function () {
    $hook = add_submenu_page('woocommerce', 'Sale Manager', 'Sale Manager', 'manage_options',
        'hambelela-sale-manager', __NAMESPACE__.'\\admin_dashboard');
    if (!$hook) return;
    add_action('admin_print_styles-'.$hook, static function () {
        wp_enqueue_style('ho-sales-admin', plugins_url('admin.css', __FILE__), [], '1.3.0');
    });
    add_action('admin_head-'.$hook, __NAMESPACE__.'\\ribbon_styles');
    add_action('admin_print_scripts-'.$hook, static function () {
        wp_enqueue_script('ho-sales-admin', plugins_url('admin.js', __FILE__), ['wp-api-fetch'], '1.3.0', true);
    });
}, 60);

function admin_dashboard() {
    if (!current_user_can('manage_options') || !current_user_can('manage_woocommerce')) {
        wp_die('You need permission to manage this shop.');
    }
    ?>
    <div class="wrap" id="ho-sales-admin">
        <header class="ho-mast"><div><span class="ho-brand">Hambelela Organic</span><h1>Sale Manager</h1></div><button id="new" type="button" class="button button-primary" disabled>New sale</button></header>
        <p id="message" role="status"></p>
        <section id="overview" class="ho-panel"><div class="ho-list-head"><h2>Campaigns</h2><label class="ho-filter">Status<select id="status-filter"><option value="">All statuses</option><option>Draft / paused</option><option>Scheduled</option><option>Active</option><option>Ended</option></select></label></div><div id="campaigns"></div></section>
        <form id="editor" hidden><fieldset id="fields" disabled>
            <div class="ho-title"><h2 id="editor-title">New sale</h2><button id="back" class="button" type="button">Back to campaigns</button></div>
            <div class="ho-workspace"><div class="ho-settings"><section class="ho-panel"><h3>Products and sizes</h3>
                <label>Sale name<input id="name" required maxlength="90" placeholder="Pre-Black Friday"></label>
                <label>Apply sale to<select id="scope"><option value="selected">Selected products or sizes</option><option value="category">Categories with exceptions</option><option value="all">Whole store with exceptions</option></select></label>
                <label id="categoryBox" hidden>Categories<select id="categories" multiple size="6"></select></label>
                <label>Find products or sizes<input id="search" type="search" placeholder="Search by name or product ID"></label>
                <div id="choices"></div><div id="picks"></div>
            </section>
            <section class="ho-panel"><h3>Discount and dates</h3><div class="ho-pair">
                <label>Offer<select id="kind"><option value="percent">Percentage off regular price</option><option value="fixed">Fixed sale price</option><option value="bundle">Buy a quantity for one price</option></select></label>
                <label><span id="unit">Percentage off</span><input id="amount" required type="number" min=".01" step=".01"></label>
                <label id="bundle" hidden>Units per bundle<input id="quantity" type="number" min="2" max="999" value="2"></label>
                <label>Starts · Windhoek<input id="start" type="datetime-local"></label><label>Ends · Windhoek<input id="end" type="datetime-local"></label></div>
                <p>Leave the start blank to start at publication. Leave the end blank to run until paused.</p>
                <label class="ho-check"><input type="checkbox" id="stack"> Allow coupons on orders using this campaign</label>
            </section>
            </div><aside class="ho-panel ho-review"><h3>Price and ribbon preview</h3>
                <div id="ribbon-preview" hidden><?php echo ribbon_html(['corner'=>true,'label'=>'PRE BLACK FRIDAY']); ?><span>Product-card ribbon</span></div>
                <p id="summary"></p><div class="ho-scroll"><table class="widefat striped"><thead><tr><th>Product or size</th><th>Current</th><th>With this offer</th></tr></thead><tbody id="preview"></tbody></table></div>
            </aside></div>
            <footer><button type="submit" id="draft" class="button" value="draft">Save draft</button> <button type="submit" id="publish" class="button button-primary" value="publish">Publish sale</button></footer>
        </fieldset></form>
    </div>
    <?php
}
