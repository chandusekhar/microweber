<?php


event_bind('mw.shop.cart_get_prices', function ($params) {


});
event_bind('mw.admin.custom_fields.price_settings', function ($data) {
    print load_module('offers/price_settings', $data);
});


api_expose_admin('offer_save');
function offer_save($offerData = array())
{
    $json = array();
    $ok = false;
    $errorMessage = '';
    $table = 'offers';


    if (isset($offerData['product_id_with_price_id'])) {
        $id_parts = explode('|', $offerData['product_id_with_price_id']);
        $offerData['product_id'] = $id_parts[0];
        $offerData['price_id'] = $id_parts[1];
    }

    if (isset($offerData['offer_price'])) {
        $offerData['offer_price'] = mw()->format->amount_to_float($offerData['offer_price']);
    }

    if (!is_numeric($offerData['offer_price'])) {
        $errorMessage .= 'offer price must be a number.<br />';
    }

    if (!empty($offerData['expires_at'])) {
        $date_db_format = get_date_db_format($offerData['expires_at']);
        $offerData['expires_at'] = date('Y-m-d H:i:s', strtotime($date_db_format));
    }

    if (empty($offerData['is_active'])) {
        $offerData['is_active'] = 0;
    } elseif ($offerData['is_active'] == 'on') {
        $offerData['is_active'] = 1;
    }

    if (empty($errorMessage)) {
        $ok = true;
    }

    if ($ok) {
        //   $offerData['price_id'] = offer_get_price_id_by_key($offerData['price_key']);
        $offerId = db_save($table, $offerData);
        $json['offer_id'] = $offerId;
        $json['success_edit'] = true;
    } else {
        $json['error_message'] = $errorMessage;
    }

    return $json;
}

api_expose_admin('offers_get_all');
function offers_get_all()
{
    $table = 'offers';

    $offers = DB::table($table)->select(


        'offers.id',
        'offers.product_id',
        //  'offers.price_key',
        'offers.offer_price',
        'offers.created_at',
        'offers.updated_at',
        'offers.expires_at',
        'offers.is_active',
        // 'offers.price_key as price_key',
        'content.title as product_title',
        'content.is_deleted',
        'custom_fields.name as price_name',
        'custom_fields_values.value as price'

    )
        ->where('content.content_type', '=', 'product')
        //->where('content.is_deleted', '=', 0)
        //  ->leftJoin('custom_fields', 'offers.price_key', '=', 'custom_fields.name_key')
        ->where('custom_fields.type', '=', 'price')
        ->leftJoin('custom_fields_values', 'custom_fields.id', '=', 'custom_fields_values.custom_field_id')
        ->leftJoin('custom_fields', 'offers.price_id', '=', 'custom_fields.id')
        ->leftJoin('content', 'offers.product_id', '=', 'content.id')
        ->get()
        ->toArray();


    // dd($offers);

    $specialOffers = array();
    foreach ($offers as $offer) {
        $specialOffers[] = get_object_vars($offer);
    }

    return $specialOffers;
}

api_expose_admin('offers_get_products');
function offers_get_products()
{
    $table = 'content';

    $offers = DB::table($table)->select('content.id as product_id', 'content.title as product_title', 'custom_fields.name as price_name', 'custom_fields.name_key as price_key', 'custom_fields_values.value as price')
        ->leftJoin('custom_fields', 'content.id', '=', 'custom_fields.rel_id')
        ->leftJoin('custom_fields_values', 'custom_fields.id', '=', 'custom_fields_values.custom_field_id')
        ->where('content.content_type', '=', 'product')
        ->where('content.is_deleted', '=', 0)
        ->where('custom_fields.type', '=', 'price')
        ->get()
        ->toArray();

    $existingOfferProductIds = offers_get_offer_product_ids();

    $specialOffers = array();
    foreach ($offers as $offer) {
        if (!in_array($offer->product_id, $existingOfferProductIds)) {
            $specialOffers[] = get_object_vars($offer);
        }
    }

    return $specialOffers;
}


//api_expose('offers_get_price');
function offers_get_price($product_id, $price_key)
{
    $offer = DB::table('offers')->select('id', 'offer_price')->where('product_id', '=', $product_id)->where('price_key', '=', $price_key)->first();
    return $offer;
}

api_expose('offers_get_by_product_id');
function offers_get_by_product_id($product_id)
{
    $table = 'offers';

    $offers = DB::table($table)->select('custom_fields.id as id', 'offers.offer_price', 'offers.expires_at', 'custom_fields.name as price_name', 'custom_fields_values.value as price')
        ->leftJoin('content', 'offers.product_id', '=', 'content.id')
        //   ->leftJoin('custom_fields', 'offers.price_key', '=', 'custom_fields.name_key')
        ->leftJoin('custom_fields', 'offers.price_id', '=', 'custom_fields.id')
        ->leftJoin('custom_fields_values', 'custom_fields.id', '=', 'custom_fields_values.custom_field_id')
        ->where('content.id', '=', (int)$product_id)
        ->where('content.is_deleted', '=', 0)
        ->where('offers.is_active', '=', 1)
        ->where('custom_fields.type', '=', 'price')
        ->get()
        ->toArray();


    $specialOffers = array();
    foreach ($offers as $offer) {


        if (empty($offer->expires_at) || $offer->expires_at == '0000-00-00 00:00:00' || (strtotime($offer->expires_at) > strtotime("now"))) {
            // converting price_name to lowercase to match key from in FieldsManager function get line 556

            $offer_data = get_object_vars($offer);
            if (isset($offer_data['offer_price']) and $offer_data['offer_price'] and isset($offer_data['price'])) {

                $price_change_direction = 'decrease';
                $offer_data['offer_price'] = floatval($offer_data['offer_price']);
                $offer_data['price'] = floatval($offer_data['price']);

                $answer = abs($offer_data['price'] - $offer_data['offer_price']);
                $offer_data['price_change_direction_sign'] = '-';
                $offer_data['offer_value_difference'] = $answer;

                if ($offer_data['offer_price'] > $offer_data['price']) {
                    $price_change_direction = 'increase';
                    $answer = abs($offer_data['price'] - $offer_data['offer_price']);
                    $offer_data['price_change_direction_sign'] = '+';
                    $offer_data['offer_value_difference'] = $answer;
                }

                $percent = mw()->format->percent($offer_data['offer_value_difference'], $offer_data['price']);
                $offer_data['offer_value_difference_percent'] = $percent;
                $offer_data['price_change_direction'] = $price_change_direction;
            }

            $specialOffers[strtolower($offer->price_name)] = $offer_data;

        }
    }

    return $specialOffers;
}


function offers_get_offer_product_ids()
{
    $offers = DB::table('offers')->select('product_id')->get();
    $product_ids = array();
    foreach ($offers as $offer) {
        $product_ids[] = $offer->product_id;
    }
    return $product_ids;
}


api_expose_admin('offer_get_by_id');
function offer_get_by_id($offer_id)
{
    $table = "offers";

    $offer = db_get($table, array(
        'id' => $offer_id,
        'single' => true,
        //  'no_cache' => true
    ));
    $addtional_fields = array();
    if (isset($offer['id']) and isset($offer['product_id']) and $offer['product_id']) {
        $prod_offers = offers_get_by_product_id($offer['product_id']);
        if ($prod_offers) {
            foreach ($prod_offers as $prod_offer) {
                if ($prod_offer['id'] == $offer['id']) {
                    $addtional_fields = $prod_offer;
                }
            }
        }
    }

    if ($addtional_fields) {
        $offer = array_merge($offer, $addtional_fields);
    }
    return $offer;

}


//@todo fix this
function offer_get_price_id_by_key($price_key)
{
    if (!is_admin()) return;

    $price_id = '';

    $table = "custom_fields";

    if ($customfield = DB::table($table)->select('id')
        ->where('name_key', '=', $price_key)
        ->where('type', '=', 'price')
        ->first()
    ) {
        $price_id = $customfield->id;
    }

    return $price_id;
}

api_expose_admin('offer_delete');
function offer_delete()
{
    if (!is_admin()) return;

    $table = "offers";
    $offerId = (int)$_POST['offer_id'];

    $delete = db_delete($table, $offerId);

    if ($delete) {
        return array(
            'status' => 'success'
        );
    } else {
        return array(
            'status' => 'failed'
        );
    }
}


