<?php
require 'db.php';
require_role(['Driver','Admin']);
$me=user();

if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    $id=(int)($_POST['order_id']??0);
    if(isset($_POST['claim'])){
        $s=$conn->prepare("UPDATE orders SET driver_id=?,status='Picked up' WHERE id=? AND driver_id IS NULL AND status='Ready for pickup'");
        $s->bind_param('ii',$me['id'],$id);$s->execute();
        if($s->affected_rows){$v='Picked up';$h=$conn->prepare('INSERT INTO order_status_history(order_id,status,changed_by) VALUES(?,?,?)');$h->bind_param('isi',$id,$v,$me['id']);$h->execute();flash('Delivery claimed.');}
    }elseif(isset($_POST['on_the_way'])){
        $s=$conn->prepare("UPDATE orders SET status='On the way' WHERE id=? AND driver_id=? AND status='Picked up'");
        $s->bind_param('ii',$id,$me['id']);$s->execute();
        if($s->affected_rows){$v='On the way';$h=$conn->prepare('INSERT INTO order_status_history(order_id,status,changed_by) VALUES(?,?,?)');$h->bind_param('isi',$id,$v,$me['id']);$h->execute();flash('Order marked on the way.');}
    }elseif(isset($_POST['deliver'])){
        $s=$conn->prepare("UPDATE orders SET status='Delivered' WHERE id=? AND driver_id=? AND status IN ('Picked up','On the way')");
        $s->bind_param('ii',$id,$me['id']);$s->execute();
        if($s->affected_rows){$v='Delivered';$h=$conn->prepare('INSERT INTO order_status_history(order_id,status,changed_by) VALUES(?,?,?)');$h->bind_param('isi',$id,$v,$me['id']);$h->execute();flash('Delivery marked complete.');}
    }elseif(isset($_POST['location'])){
        $lat=(float)($_POST['latitude']??0);$lng=(float)($_POST['longitude']??0);
        if($lat>=-90&&$lat<=90&&$lng>=-180&&$lng<=180){$s=$conn->prepare('INSERT INTO delivery_locations(order_id,driver_id,latitude,longitude) SELECT id,?,?,? FROM orders WHERE id=? AND driver_id=?');$s->bind_param('iddii',$me['id'],$lat,$lng,$id,$me['id']);$s->execute();flash('Location recorded.');}
    }
    header('Location: courier.php');exit;
}
$s=$conn->prepare("SELECT o.*,r.name restaurant FROM orders o JOIN restaurants r ON r.id=o.restaurant_id WHERE (o.status='Ready for pickup' AND o.driver_id IS NULL) OR o.driver_id=? ORDER BY o.created_at");
$s->bind_param('i',$me['id']);$s->execute();$jobs=$s->get_result();
page_start('Courier jobs');?><section class="hero"><h1>Courier deliveries</h1><p>Claim ready orders, share your current location with the order record, and complete deliveries.</p></section><?php $msg=take_flash();if($msg)echo '<p class="pill">'.h($msg).'</p>';while($o=$jobs->fetch_assoc()):?><article class="card" style="margin-bottom:14px"><h2>Order #<?=$o['id']?> · <?=h($o['restaurant'])?></h2><p><?=h($o['delivery_address'])?> · <?=h($o['status'])?></p><?php if(!$o['driver_id']):?><form method="post"><?php csrf_field();?><input type="hidden" name="order_id" value="<?=$o['id']?>"><button name="claim">Claim delivery</button></form><?php else:?><?php if($o['status']==='Picked up'):?><form method="post" class="inline"><?php csrf_field();?><input type="hidden" name="order_id" value="<?=$o['id']?>"><button class="secondary" name="on_the_way">Mark on the way</button></form><?php endif;?><?php if(in_array($o['status'],['Picked up','On the way'],true)):?><form method="post" class="inline"><?php csrf_field();?><input type="hidden" name="order_id" value="<?=$o['id']?>"><button class="secondary" name="deliver">Mark delivered</button></form><button type="button" onclick="shareLocation(<?=$o['id']?>)">Share my GPS location</button><?php endif;?><?php endif;?></article><?php endwhile;?><form method="post" id="gps" style="display:none"><?php csrf_field();?><input type="hidden" name="location" value="1"><input name="order_id" id="gps-order"><input name="latitude" id="lat"><input name="longitude" id="lng"></form><script>function shareLocation(id){if(!navigator.geolocation){alert('This browser does not support GPS.');return}navigator.geolocation.getCurrentPosition(p=>{document.getElementById('gps-order').value=id;document.getElementById('lat').value=p.coords.latitude;document.getElementById('lng').value=p.coords.longitude;document.getElementById('gps').requestSubmit()},e=>alert('Could not read location: '+e.message),{enableHighAccuracy:true,timeout:10000})}</script><?php page_end();
