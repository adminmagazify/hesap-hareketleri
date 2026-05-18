<?php
/*
Plugin Name: Hesap Hareketleri
Description: Sipariş hareketleri + özet (ÖDEME) satırları yöneten özel raporlama eklentisi.
Version: 2.0
Author: Magazac
*/

require plugin_dir_path(__FILE__) . 'plugin-update-checker-master/plugin-update-checker.php';

$updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/adminmagazify/hesap-hareketleri',
    __FILE__,
    'hesap-hareketleri'
);

$updateChecker->setBranch('main');




if (!defined("ABSPATH")) exit;

/* --------------------------------------------------------
   CSS yükle
-------------------------------------------------------- */
add_action("admin_enqueue_scripts", function () {
    wp_enqueue_style("hh-style", plugin_dir_url(__FILE__) . "style.css");
});
add_action("wp_enqueue_scripts", function () {
    wp_enqueue_style("hh-style-front", plugin_dir_url(__FILE__) . "style.css");
});


/* --------------------------------------------------------
   Admin Menü
-------------------------------------------------------- */
add_action("admin_menu", function () {
    add_menu_page(
        "Hesap Hareketleri",
        "Hesap Hareketleri",
        "manage_woocommerce",
        "hesap-hareketleri",
        "hh_admin_page",
        "dashicons-chart-line",
        27
    );
});


/* --------------------------------------------------------
   ÖDEME satırı post type
-------------------------------------------------------- */
add_action("init", function () {
    register_post_type("hesap_ozet", [
        "public"      => false,
        "show_ui"     => false,
        "supports"    => ['title']
    ]);
});


/* --------------------------------------------------------
   Kâr Hesabı
-------------------------------------------------------- */
function hh_hesapla_kar($order) {

    $subtotal = $order->get_subtotal();
    $total    = $order->get_total();

    $brut = ($subtotal / 1.1) * 0.25;
    $net  = $brut * 0.8;

    return [
        "brut"   => $brut,
        "net"    => $net,
        "total"  => $total
    ];
}


/* --------------------------------------------------------
   Hareketleri getir (siparişler + ÖDEME satırları)
-------------------------------------------------------- */
function hh_get_hareketler() {

    $hareketler = [];

    /* ---------------------------
       1) TAMAMLANAN siparişler
    ---------------------------- */
    $orders = wc_get_orders([
        "status"  => "completed",
        "orderby" => "date",
        "order"   => "ASC",
        "limit"   => -1
    ]);

    foreach ($orders as $order) {

        $kar = hh_hesapla_kar($order);
        $hesap_durumu = get_post_meta($order->get_id(), "_hesap_durumu", true) ?: "boş";

        $hareketler[] = [
            "type"     => "order",
            "id"       => $order->get_id(),
            "date"     => $order->get_date_created()->getTimestamp(),
            "status"   => wc_get_order_status_name($order->get_status()),
            "customer" => $order->get_formatted_billing_full_name(),
            "net"      => $kar["net"],
            "brut"     => $kar["brut"],
            "total"    => $kar["total"],
            "durum"    => $hesap_durumu
        ];
    }

    /* ---------------------------
       2) ÖDEME (özet) satırları
    ---------------------------- */
    $ozetler = get_posts([
        "post_type"      => "hesap_ozet",
        "posts_per_page" => -1,
        "orderby"        => "date",
        "order"          => "ASC"
    ]);

    foreach ($ozetler as $p) {

        $hareketler[] = [
            "type"     => "ozet",
            "id"       => $p->ID,
            "date"     => strtotime($p->post_date),
            "status"   => "-",
            "customer" => "-",
            "net"      => get_post_meta($p->ID, "net", true),
            "brut"     => get_post_meta($p->ID, "brut", true),
            "total"    => get_post_meta($p->ID, "total", true),
            "durum"    => "ödeme sürecinde"   // tabloya yazılan metin
        ];
    }

    /* ---------------------------
       3) Tarihe göre sırala
    ---------------------------- */
    usort($hareketler, function ($a, $b) {
        return $a["date"] - $b["date"];
    });

    return $hareketler;
}



/* --------------------------------------------------------
   Güncelleme işlemi (admin)
-------------------------------------------------------- */
add_action("admin_post_hh_update_status", function () {

    if (!current_user_can("manage_woocommerce")) wp_die("Yetkiniz yok");

    $siparisler = isset($_POST["siparis"]) ? $_POST["siparis"] : [];
    $durumlar   = isset($_POST["durum"]) ? $_POST["durum"] : [];

    $top_net   = 0;
    $top_brut  = 0;
    $top_total = 0;

    $islem_var = false;

    foreach ($siparisler as $order_id) {

        $secim = $durumlar[$order_id] ?? "boş";

        // ÖZET satırı sadece bu seçilince oluşturulacak
        if ($secim !== "ödeme emri verildi") {
            update_post_meta($order_id, "_hesap_durumu", $secim);
            continue;
        }

        // ödeme emri verildi seçilmişse
        update_post_meta($order_id, "_hesap_durumu", "ödeme emri verildi");

        $order = wc_get_order($order_id);
        $kar   = hh_hesapla_kar($order);

        $top_net   += $kar["net"];
        $top_brut  += $kar["brut"];
        $top_total += $kar["total"];

        $islem_var = true;
    }


    /* ---------------------------------------------------
       Eğer en az 1 sipariş “ödeme emri verildi” ise
       → Yeni ÖDEME satırı oluştur
    --------------------------------------------------- */
    if ($islem_var) {

        $ozet_id = wp_insert_post([
            "post_type"   => "hesap_ozet",
            "post_status" => "publish",
            "post_title"  => "ÖDEME"
        ]);

        update_post_meta($ozet_id, "net",   $top_net);
        update_post_meta($ozet_id, "brut",  $top_brut);
        update_post_meta($ozet_id, "total", $top_total);
    }

    wp_redirect($_SERVER["HTTP_REFERER"]);
    exit;
});


/* --------------------------------------------------------
   ÖDEME SATIRINI SİLME İŞLEMİ
-------------------------------------------------------- */
add_action("admin_post_hh_delete_ozet", function () {

    if (!current_user_can("manage_woocommerce")) wp_die("Yetki yok");

    $id = intval($_GET["id"] ?? 0);
    if ($id > 0) {
        wp_delete_post($id, true);
    }

    wp_redirect($_SERVER["HTTP_REFERER"]);
    exit;
});


/* --------------------------------------------------------
   Admin Panel Sayfası
-------------------------------------------------------- */
function hh_admin_page() {

    $hareketler = hh_get_hareketler();

    echo "<h1>Hesap Hareketleri</h1>";

    echo '<form method="post" action="'.admin_url("admin-post.php").'">';
    echo '<input type="hidden" name="action" value="hh_update_status">';

    echo '<table class="widefat striped">';
    echo '<thead>
            <tr>
                <th>#</th>
                <th>Tarih</th>
                <th>Durum</th>
                <th>Net Kâr(2)</th>
                <th>Brüt Kâr(1)</th>
                <th>Toplam</th>
                <th>Müşteri</th>
                <th>Hesap Durumu</th>
                <th>Sil</th>
            </tr>
          </thead>';

    echo "<tbody>";

    foreach ($hareketler as $h) {

        echo "<tr>";

        /* ------------------------------
           SİPARİŞ SATIRLARI
        ------------------------------ */
        if ($h["type"] === "order") {

            echo "<td>
                    <input type='checkbox' name='siparis[]' value='{$h["id"]}'>
                    #{$h["id"]}
                  </td>";

            echo "<td>".date("d.m.Y", $h["date"])."</td>";
            echo "<td>{$h["status"]}</td>";
            echo "<td>".wc_price($h["net"])."</td>";
            echo "<td>".wc_price($h["brut"])."</td>";
            echo "<td>".wc_price($h["total"])."</td>";
            echo "<td>{$h["customer"]}</td>";

            echo "<td>
                    <select name='durum[{$h["id"]}]'>
                        <option value='boş' ".selected($h["durum"], "boş", false).">boş</option>
                        <option value='ödeme emri verildi' ".selected($h["durum"], "ödeme emri verildi", false).">
                            ödeme emri verildi
                        </option>
                        <option value='geçersiz sipariş' ".selected($h["durum"], "geçersiz sipariş", false).">
                            geçersiz sipariş
                        </option>
                    </select>
                  </td>";

            echo "<td>-</td>";
        }

        /* ------------------------------
           ÖDEME SATIRLARI
        ------------------------------ */
        else {

            echo "<td><strong>ÖDEME</strong></td>";
            echo "<td>".date("d.m.Y", $h["date"])."</td>";
            echo "<td>-</td>";
            echo "<td>".wc_price($h["net"])."</td>";
            echo "<td>".wc_price($h["brut"])."</td>";
            echo "<td>".wc_price($h["total"])."</td>";
            echo "<td>-</td>";
            echo "<td><strong>ödeme sürecinde</strong></td>";

            echo "<td>
                    <a href='".admin_url("admin-post.php?action=hh_delete_ozet&id={$h["id"]}")."'
                       class='button button-secondary'
                       onclick='return confirm(\"Bu ödeme satırını silmek istediğinize emin misiniz?\")'>
                       Sil
                    </a>
                  </td>";
        }

        echo "</tr>";
    }

    echo "</tbody></table>";

    echo '<button type="submit" class="button button-primary" style="margin-top:15px;">Güncelle</button>';
    echo '</form>';
}


/* --------------------------------------------------------
   SHORTCODE (Kullanıcı Paneli)
-------------------------------------------------------- */
add_shortcode("hesap-hareketleri", function () {

    if (!current_user_can("manage_woocommerce") &&
        !current_user_can("manage_store_panel")) {
        return "<p>Bu alanı görüntüleme yetkiniz yok.</p>";
    }

    $hareketler = hh_get_hareketler();

    ob_start();

    echo "<h3>Hesap Hareketleri</h3>";

    echo '<table class="widefat striped">';
    echo '<thead>
            <tr>
                <th>Sipariş No</th>
                <th>Tarih</th>
                <th>Durum</th>
                <th>Net Kâr(2)</th>
                <th>Brüt Kâr(1)</th>
                <th>Toplam</th>
                <th>Müşteri</th>
                <th>Hesap Durumu</th>
            </tr>
          </thead>';

    echo "<tbody>";

    foreach ($hareketler as $h) {

        echo "<tr>";

        echo "<td>" . ($h["type"] === "order" ? "#".$h["id"] : "ÖDEME") . "</td>";
        echo "<td>".date("d.m.Y", $h["date"])."</td>";
        echo "<td>{$h["status"]}</td>";
        echo "<td>".wc_price($h["net"])."</td>";
        echo "<td>".wc_price($h["brut"])."</td>";
        echo "<td>".wc_price($h["total"])."</td>";
        echo "<td>{$h["customer"]}</td>";
        echo "<td>{$h["durum"]}</td>";

        echo "</tr>";
    }

    echo "</tbody></table>";

    return ob_get_clean();
});