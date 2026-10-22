<?php require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_permission('manage_products');
$id=(int)($_GET['id']??0);$row=null;if($id){$cols=is_super_admin()?'*':'id,sku,product_barcode,product_name,product_image,category_id,brand,fabric,pattern_name,gender,season,color,size,design_model,hsn_code,unit,min_stock,serial_tracking,active';$st=db()->prepare('SELECT '.$cols.' FROM products WHERE id=?');$st->execute([$id]);$row=$st->fetch();if(!$row)exit('Product not found');}
$cats=db()->query('SELECT * FROM categories WHERE active=1 ORDER BY name')->fetchAll();$err='';

function save_product_image_upload(?string $current=null): ?string {
    if(empty($_FILES['product_image']) || ($_FILES['product_image']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return $current;
    $f=$_FILES['product_image'];
    if(($f['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK) throw new Exception('Product image upload failed.');
    if((int)$f['size']>5*1024*1024) throw new Exception('Product image must be 5 MB or less.');
    $fi=new finfo(FILEINFO_MIME_TYPE);$mime=$fi->file($f['tmp_name']);
    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
    if(!$ext) throw new Exception('Only JPG, PNG or WEBP product images are allowed.');
    $dir=__DIR__.'/uploads/products';if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new Exception('Product image folder is not writable.');
    $name='p_'.bin2hex(random_bytes(12)).'.'.$ext;$dest=$dir.'/'.$name;
    if(!move_uploaded_file($f['tmp_name'],$dest)) throw new Exception('Could not save product image.');
    return 'uploads/products/'.$name;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check(); $isAdmin=is_super_admin();
 if(!$id){$postedId=(int)($_POST['existing_id']??0);if($postedId>0){$id=$postedId;$st=db()->prepare('SELECT * FROM products WHERE id=?');$st->execute([$id]);$row=$st->fetch()?:null;if(!$row)$id=0;}}
 $data=['sku'=>trim($_POST['sku']??''),'barcode'=>trim($_POST['product_barcode']??''),'name'=>trim($_POST['product_name']??''),'category'=>(int)($_POST['category_id']??0),'brand'=>trim($_POST['brand']??'Pratyangi Fashions'),'fabric'=>trim($_POST['fabric']??''),'pattern'=>trim($_POST['pattern_name']??''),'gender'=>trim($_POST['gender']??''),'season'=>trim($_POST['season']??''),'color'=>trim($_POST['color']??''),'size'=>trim($_POST['size']??''),'design'=>trim($_POST['design_model']??''),'hsn'=>trim($_POST['hsn_code']??''),'unit'=>trim($_POST['unit']??'PCS'),'min'=>(float)($_POST['min_stock']??0)];
 if(!$data['sku']||!$data['barcode']||!$data['name'])$err='SKU, barcode and product name are required.';
 elseif(!preg_match('/^[\x21-\x7E]{3,80}$/',$data['barcode']))$err='Barcode must be 3-80 characters using only English letters, numbers and symbols, with no spaces, so it can be printed and scanned.';
 else try{
  $image=save_product_image_upload($row['product_image']??null);
  if($id){
   if($isAdmin){$cost=max(0,(float)($_POST['purchase_cost']??0));$costCode=trim($_POST['cost_code']??'');$mrp=max(0,(float)($_POST['mrp']??0));$ret=max(0,(float)($_POST['retail_price']??0));$wh=max(0,(float)($_POST['wholesale_price']??0));$de=max(0,(float)($_POST['dealer_price']??0));$off=($_POST['offer_price']??'')===''?null:max(0,(float)$_POST['offer_price']);$tax=max(0,(float)($_POST['tax_rate']??0));$serial=isset($_POST['serial_tracking'])?1:0;if($row&&$serial!==(int)$row['serial_tracking']){$stc=db()->prepare('SELECT COALESCE(SUM(qty),0) FROM inventory_balances WHERE product_id=?');$stc->execute([$id]);$stockNow=(float)$stc->fetchColumn();if($stockNow>0)throw new Exception('Cannot change barcode tracking mode while stock exists. Sell/return/migrate existing stock first.');}$sql='UPDATE products SET sku=?,product_barcode=?,product_name=?,product_image=?,category_id=?,brand=?,fabric=?,pattern_name=?,gender=?,season=?,color=?,size=?,design_model=?,hsn_code=?,unit=?,purchase_cost=?,cost_code=?,mrp=?,retail_price=?,wholesale_price=?,dealer_price=?,offer_price=?,tax_rate=?,min_stock=?,serial_tracking=? WHERE id=?';$args=[$data['sku'],$data['barcode'],$data['name'],$image,$data['category']?:null,$data['brand'],$data['fabric'],$data['pattern'],$data['gender'],$data['season'],$data['color'],$data['size'],$data['design'],$data['hsn'],$data['unit'],$cost,$costCode,$mrp,$ret,$wh,$de,$off,$tax,$data['min'],$serial,$id];}
   else {$sql='UPDATE products SET sku=?,product_barcode=?,product_name=?,product_image=?,category_id=?,brand=?,fabric=?,pattern_name=?,gender=?,season=?,color=?,size=?,design_model=?,hsn_code=?,unit=?,min_stock=? WHERE id=?';$args=[$data['sku'],$data['barcode'],$data['name'],$image,$data['category']?:null,$data['brand'],$data['fabric'],$data['pattern'],$data['gender'],$data['season'],$data['color'],$data['size'],$data['design'],$data['hsn'],$data['unit'],$data['min'],$id];}
   db()->prepare($sql)->execute($args);audit('PRODUCT_UPDATE','product',$id);publish_event('PRODUCT_UPDATE','Product / rate updated',$data['sku'].' · '.$data['name'],['super_admin','manager','godown','cashier'],'product',$id,null);flash('product_saved',$data['sku'].' · '.$data['name'].' saved.');redirect('products.php');
  } else {
   $cost=$isAdmin?max(0,(float)($_POST['purchase_cost']??0)):0;$costCode=$isAdmin?trim($_POST['cost_code']??''):'';if(!$costCode)$costCode='C'.strtoupper(bin2hex(random_bytes(3)));$mrp=$isAdmin?max(0,(float)($_POST['mrp']??0)):0;$ret=$isAdmin?max(0,(float)($_POST['retail_price']??0)):0;$wh=$isAdmin?max(0,(float)($_POST['wholesale_price']??0)):0;$de=$isAdmin?max(0,(float)($_POST['dealer_price']??0)):0;$off=$isAdmin&&($_POST['offer_price']??'')!==''?max(0,(float)$_POST['offer_price']):null;$tax=$isAdmin?max(0,(float)($_POST['tax_rate']??0)):0;$serial=$isAdmin?(isset($_POST['serial_tracking'])?1:0):1;
   $st=db()->prepare('INSERT INTO products(sku,product_barcode,product_name,product_image,category_id,brand,fabric,pattern_name,gender,season,color,size,design_model,hsn_code,unit,purchase_cost,cost_code,mrp,retail_price,wholesale_price,dealer_price,offer_price,tax_rate,min_stock,serial_tracking) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
   $st->execute([$data['sku'],$data['barcode'],$data['name'],$image,$data['category']?:null,$data['brand'],$data['fabric'],$data['pattern'],$data['gender'],$data['season'],$data['color'],$data['size'],$data['design'],$data['hsn'],$data['unit'],$cost,$costCode,$mrp,$ret,$wh,$de,$off,$tax,$data['min'],$serial]);$nid=(int)db()->lastInsertId();audit('PRODUCT_CREATE','product',$nid);publish_event('PRODUCT_CREATE','New product created',$data['sku'].' · '.$data['name'],['super_admin','manager','godown','cashier'],'product',$nid,null);flash('product_saved',$data['sku'].' · '.$data['name'].' saved.');redirect('products.php');
  }
 }catch(Throwable $e){$err=user_error_message($e,'This SKU or barcode is already used by another product. Use a different SKU/barcode or edit the existing product.');}
}
$v=$row??[]; page_header($id?'Edit Product':'Add Product'); if($err)echo '<div class="notice error">'.e($err).'</div>'; ?>
<form method="post" enctype="multipart/form-data" class="panel" id="productForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="existing_id" id="existing_id" value="0"><div id="productMatch" class="notice" hidden></div><div class="row3"><div><label>SKU / Product Code</label><input name="sku" id="sku" required value="<?=e($v['sku']??'')?>" autocomplete="off"></div><div><label>Base Product Barcode</label><input name="product_barcode" id="product_barcode" required value="<?=e($v['product_barcode']??('PF'.date('ymdHis')))?>" autocomplete="off"></div><div><label>Product Name</label><input name="product_name" id="product_name" required value="<?=e($v['product_name']??'')?>"></div></div><br>
<div class="row"><div><label>Product Photo (JPG/PNG/WEBP · max 5MB)</label><input type="file" name="product_image" accept="image/jpeg,image/png,image/webp"></div><div><?php if(!empty($v['product_image'])):?><label>Current Photo</label><br><img src="<?=e($v['product_image'])?>" alt="Product" style="width:120px;height:120px;object-fit:cover;border-radius:10px"><?php else:?><div class="muted">Photo will be shown to Godown staff during online picking/packing.</div><?php endif;?></div></div><br>
<div class="row3"><div><label>Category</label><select name="category_id" id="category_id"><option value="">--</option><?php foreach($cats as $c):?><option value="<?=$c['id']?>" <?=($v['category_id']??'')==$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></div><div><label>Brand</label><input name="brand" value="<?=e($v['brand']??'Pratyangi Fashions')?>"></div><div><label>Fabric</label><input name="fabric" value="<?=e($v['fabric']??'')?>"></div></div><br>
<div class="row3"><div><label>Pattern</label><input name="pattern_name" value="<?=e($v['pattern_name']??'')?>"></div><div><label>Gender</label><select name="gender"><option value="">--</option><?php foreach(['WOMEN','MEN','KIDS','UNISEX'] as $g):?><option <?=($v['gender']??'')===$g?'selected':''?>><?=$g?></option><?php endforeach;?></select></div><div><label>Season / Collection</label><input name="season" value="<?=e($v['season']??'')?>"></div></div><br>
<div class="row3"><div><label>Color</label><input name="color" value="<?=e($v['color']??'')?>"></div><div><label>Size</label><input name="size" value="<?=e($v['size']??'')?>"></div><div><label>Design / Model</label><input name="design_model" value="<?=e($v['design_model']??'')?>"></div></div><br>
<div class="row3"><div><label>HSN</label><input name="hsn_code" value="<?=e($v['hsn_code']??'')?>"></div><div><label>Unit</label><input name="unit" value="<?=e($v['unit']??'PCS')?>"></div><div><label>Minimum Stock Alert</label><input type="number" step="0.001" name="min_stock" value="<?=e($v['min_stock']??0)?>"></div></div><br>
<?php if(is_super_admin()):?><div class="notice"><b>Super Admin pricing:</b> Staff users cannot change purchase cost or selling rates.</div><div class="row"><div><label>Purchase / Original Cost · ADMIN ONLY</label><input type="number" step="0.01" min="0" name="purchase_cost" value="<?=e($v['purchase_cost']??0)?>"></div><div><label>Internal Cost Code · ADMIN ONLY</label><input name="cost_code" value="<?=e($v['cost_code']??'')?>" placeholder="Auto-generated if blank"></div></div><br>
<div class="row3"><div><label>MRP</label><input type="number" step="0.01" min="0" name="mrp" value="<?=e($v['mrp']??0)?>"></div><div><label>Retail Price</label><input type="number" step="0.01" min="0" name="retail_price" value="<?=e($v['retail_price']??0)?>"></div><div><label>Wholesale Price</label><input type="number" step="0.01" min="0" name="wholesale_price" value="<?=e($v['wholesale_price']??0)?>"></div></div><br><div class="row3"><div><label>Dealer Price</label><input type="number" step="0.01" min="0" name="dealer_price" value="<?=e($v['dealer_price']??0)?>"></div><div><label>Offer Price</label><input type="number" step="0.01" min="0" name="offer_price" value="<?=e($v['offer_price']??'')?>"></div><div><label>GST %</label><input type="number" step="0.01" min="0" name="tax_rate" value="<?=e($v['tax_rate']??0)?>"></div></div><br><label><input type="checkbox" name="serial_tracking" value="1" style="width:auto" <?=($v['serial_tracking']??1)?'checked':''?>> Unique piece barcode tracking (recommended for garments)</label><br><br><?php else:?><div class="notice"><?=$id?'Selling rates, GST, purchase cost and serial-tracking mode can be changed only by Super Admin.':'Unique piece barcode tracking is turned on for this product. Selling rates, GST and purchase cost are set later by Super Admin.'?></div><?php endif;?><button class="btn">Save Product</button></form>
<script>
(function(){
 const sku=document.getElementById('sku');
 const barcode=document.getElementById('product_barcode');
 const box=document.getElementById('productMatch');
 const existing=document.getElementById('existing_id');
 if(!sku||!barcode)return;
 const set=(id,value)=>{const el=document.getElementById(id);if(el&&value!==undefined&&value!==null)el.value=value;};
 let timer;
 async function lookup(){
  const s=sku.value.trim(), b=barcode.value.trim();
  if(!s||!b){return;}
  const r=await fetch('api/product_lookup.php?sku='+encodeURIComponent(s)+'&barcode='+encodeURIComponent(b),{credentials:'same-origin'});
  const j=await r.json();
  if(!j.ok)return;
  if(!j.found){existing.value='0';box.hidden=false;box.textContent='New product. Fill the remaining fields and press Save Product.';return;}
  const p=j.product;
  existing.value=p.id;
  set('product_name',p.product_name||'');
  set('category_id',p.category_id||'');
  const brand=document.querySelector('[name=brand]'); if(brand) brand.value=p.brand||'';
  const fabric=document.querySelector('[name=fabric]'); if(fabric) fabric.value=p.fabric||'';
  const pattern=document.querySelector('[name=pattern_name]'); if(pattern) pattern.value=p.pattern_name||'';
  const gender=document.querySelector('[name=gender]'); if(gender) gender.value=p.gender||'';
  const season=document.querySelector('[name=season]'); if(season) season.value=p.season||'';
  const color=document.querySelector('[name=color]'); if(color) color.value=p.color||'';
  const size=document.querySelector('[name=size]'); if(size) size.value=p.size||'';
  const design=document.querySelector('[name=design_model]'); if(design) design.value=p.design_model||'';
  const hsn=document.querySelector('[name=hsn_code]'); if(hsn) hsn.value=p.hsn_code||'';
  const unit=document.querySelector('[name=unit]'); if(unit) unit.value=p.unit||'PCS';
  const min=document.querySelector('[name=min_stock]'); if(min) min.value=p.min_stock||0;
  if(!sku.value.trim()) sku.value=p.sku||'';
  if(!barcode.value.trim()) barcode.value=p.product_barcode||'';
  box.hidden=false;
  box.textContent='Product found: '+p.sku+' · '+p.product_name+' · Barcode '+p.product_barcode+'. Press Save Product to keep it.';
 }
 function schedule(){clearTimeout(timer);timer=setTimeout(lookup,350);}
 sku.addEventListener('change',schedule);
 barcode.addEventListener('change',schedule);
 sku.addEventListener('input',schedule);
 barcode.addEventListener('input',schedule);
})();
</script>
<?php page_footer();
