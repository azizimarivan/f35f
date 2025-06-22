<?php
/********************************************************************************
 MachForm
  
 Copyright 2007-2014 Appnitro Software. This code cannot be redistributed without
 permission from http://www.appnitro.com/
 
 More info at: http://www.appnitro.com/
 ********************************************************************************/	
	require('includes/init.php');
	
	require('config.php');
	require('includes/db-core.php');
	require('includes/helper-functions.php');
	require('includes/check-session.php');

	require('includes/entry-functions.php');
	require('includes/users-functions.php');
	
	$form_id = (int) trim($_GET['id']);
	$sort_by = trim($_GET['sortby']);

	//get page number for pagination
	if (isset($_REQUEST['pageno'])) {
	   $pageno = $_REQUEST['pageno'];
	}else{
	   $pageno = 1;
	}

	
	$dbh = mf_connect_db();
	$mf_settings = mf_get_settings($dbh);
	
	//check permission, is the user allowed to access this page?
	if(empty($_SESSION['mf_user_privileges']['priv_administer'])){
		$user_perms = mf_get_user_permissions($dbh,$form_id,$_SESSION['mf_user_id']);

		//this page need edit_entries or view_entries permission
		if(empty($user_perms['edit_entries']) && empty($user_perms['view_entries'])){
			$_SESSION['MF_DENIED'] = "شما اجازه دسترسی به این صفحه را ندارید.";

			$ssl_suffix = mf_get_ssl_suffix();						
			header("Location: http{$ssl_suffix}://".$_SERVER['HTTP_HOST'].mf_get_dirname($_SERVER['PHP_SELF'])."/restricted.php");
			exit;
		}
	}
	
	$query = "select 
					A.form_name,
					ifnull(B.entries_sort_by,'id-desc') entries_sort_by,
					ifnull(B.entries_filter_type,'all') entries_filter_type,
					ifnull(B.entries_enable_filter,0) entries_enable_filter			  
				from 
					".MF_TABLE_PREFIX."forms A left join ".MF_TABLE_PREFIX."entries_preferences B 
				  on 
				  	A.form_id=B.form_id and B.user_id=? 
			   where 
			   		A.form_id = ?";
	$params = array($_SESSION['mf_user_id'],$form_id);
	
	$sth = mf_do_query($query,$params,$dbh);
	$row = mf_do_fetch_result($sth);
	
	if(!empty($row)){
		
		$row['form_name'] = mf_trim_max_length($row['form_name'],65);

		if(!empty($row['form_name'])){		
			$form_name = htmlspecialchars($row['form_name']);
		}else{
			$form_name = 'فرم بدون عنوان (#'.$form_id.')';
		}	

		$entries_filter_type   = $row['entries_filter_type'];
		$entries_enable_filter = $row['entries_enable_filter'];
	}else{
		die("خطا.آی دی فرم ناشناخته.");
	}

	if(empty($sort_by)){
		//get the default sort element from the table
		$sort_by = $row['entries_sort_by'];
	}else{
		//if sort by parameter exist, save it into the database
		$query = "select count(user_id) sort_count from ".MF_TABLE_PREFIX."entries_preferences where form_id=? and `user_id`=?";
		
		$params = array($form_id,$_SESSION['mf_user_id']);
		$sth = mf_do_query($query,$params,$dbh);
		$row = mf_do_fetch_result($sth);
		
		$sort_count = $row['sort_count'];

		if(!empty($sort_count)){ //update existing record
			$query = "update ".MF_TABLE_PREFIX."entries_preferences set entries_sort_by = ? where form_id = ? and `user_id` = ?";
			$params = array($sort_by,$form_id,$_SESSION['mf_user_id']);
			mf_do_query($query,$params,$dbh);
		}else{ //insert new one
			$query = "insert into ".MF_TABLE_PREFIX."entries_preferences(`entries_sort_by`,`form_id`,`user_id`) values(?,?,?)";
			$params = array($sort_by,$form_id,$_SESSION['mf_user_id']);
			mf_do_query($query,$params,$dbh);
		}
		
	}

	$jquery_data_code = '';

	//get all available columns label
	$columns_meta  = mf_get_columns_meta($dbh,$form_id);
	$columns_label = $columns_meta['name_lookup'];
	$columns_type  = $columns_meta['type_lookup'];

	$form_properties = mf_get_form_properties($dbh,$form_id,array('payment_enable_merchant','form_resume_enable'));
	
	//if payment enabled, add ap_form_payments columns into $columns_label
	if($form_properties['payment_enable_merchant'] == 1){
		$columns_label['payment_amount'] = 'مبلغ پرداختی';
		$columns_label['payment_status'] = 'وضعیت پرداختی';
		$columns_label['payment_id']	 = 'آی دی پرداخت';

		$columns_type['payment_amount'] = 'مبلغ';
		$columns_type['payment_status'] = 'متن';
		$columns_type['payment_id'] 	= 'متن';
	}

	//get current column preference
	$query = "select element_name from ".MF_TABLE_PREFIX."column_preferences where form_id=? and user_id=? and incomplete_entries=0";
	$params = array($form_id,$_SESSION['mf_user_id']);

	$sth = mf_do_query($query,$params,$dbh);
	while($row = mf_do_fetch_result($sth)){
		$current_column_preference[] = $row['element_name'];
	}

	//if the form has "resume" enable, calculate any incomplete entries
	if(!empty($form_properties['form_resume_enable'])){
		$query = "select count(*) total_row from `".MF_TABLE_PREFIX."form_{$form_id}` where `status`=2 and `resume_key` is not null";
		$params = array();
				
		$sth = mf_do_query($query,$params,$dbh);
		$row = mf_do_fetch_result($sth);

		$total_incomplete_entries = $row['total_row'];
	}

	//check if the table has entries or not
	$query = "select count(*) total_row from `".MF_TABLE_PREFIX."form_{$form_id}` where `status`=1";
	$params = array();
			
	$sth = mf_do_query($query,$params,$dbh);
	$row = mf_do_fetch_result($sth);
		
	if(!empty($row['total_row'])){
		$form_has_entries = true;
	}else{
		$form_has_entries = false;
	}

	//prepare the jquery data for column type lookup
	foreach ($columns_type as $element_name => $element_type) {
		if($element_type == 'checkbox'){
			if(substr($element_name, -5) == 'other'){
				$element_type = 'checkbox_other';
			}
		}

		$jquery_data_code .= "\$('#filter_pane').data('$element_name','$element_type');\n";
	}


	//get filter keywords from ap_form_filters table
	$query = "select
					element_name,
					filter_condition,
					filter_keyword
				from 
					".MF_TABLE_PREFIX."form_filters
			   where
			   		form_id = ? and user_id = ? and incomplete_entries = 0 
			order by 
			   		aff_id asc";
	$params = array($form_id,$_SESSION['mf_user_id']);
	$sth = mf_do_query($query,$params,$dbh);
	$i = 0;
	while($row = mf_do_fetch_result($sth)){
		$filter_data[$i]['element_name'] 	 = $row['element_name'];
		$filter_data[$i]['filter_condition'] = $row['filter_condition'];
		$filter_data[$i]['filter_keyword'] 	 = $row['filter_keyword'];
		$i++;
	}

			$header_data =<<<EOT
<link type="text/css" href="js/jquery-ui/themes/base/jquery.ui.all.css" rel="stylesheet" />
<link type="text/css" href="css/pagination_classic.css" rel="stylesheet" />
<link type="text/css" href="css/dropui.css" rel="stylesheet" />
<link type="text/css" href="js/datepick/smoothness.datepick.css" rel="stylesheet" />
EOT;
	
	$current_nav_tab = 'manage_forms';
	require('includes/header.php'); 
	
?>


		<div id="content" class="full">
			<div class="post manage_entries">
				<div class="content_header">
					<div class="content_header_title">
						<div id="me_form_title" <?php if(!empty($total_incomplete_entries)){ echo 'style="max-width: 80%"'; } ?>>
							<h2><?php echo "<a class=\"breadcrumb\" href='manage_forms.php?id={$form_id}'>".$form_name.'</a>'; ?> <img src="images/icons/resultset_next.gif" /> مطالب</h2>
							<p>مطالبتان را ویرایش و مدیریت کنید</p>
						</div>
						
						<?php if(!empty($total_incomplete_entries)){ ?>
							<div id="me_incomplete_entries_info">
								<a style="color: #fff" href="manage_incomplete_entries.php?id=<?php echo $form_id; ?>"><?php echo $total_incomplete_entries; ?> ورودی های ناقص</a>		
							</div>
						<?php } ?>

						<div style="clear: both; height: 1px"></div>
					</div>
					
				</div>

				<?php mf_show_message(); ?>

				<div class="content_body">
					
					<?php if($form_has_entries){ ?>
					
						<div id="entries_actions" class="gradient_red">
							<ul>
								
								<?php if(!empty($_SESSION['mf_user_privileges']['priv_administer']) || !empty($user_perms['edit_entries'])){ ?>
								<li>
									<a id="entry_delete" href="#"><span class="icon-remove"></span>حذف</a>
								</li>
								<?php } ?>

								<li>
									<div style="border-left: 1px dotted #CB6852;height: 35px;margin-top:5px"></div>
								</li>
								<li>
									<a id="entry_export" href="#"><span class="icon-file-download"></span>خروجی گرفتن</a>
								</li>
							</ul>
							<img src="images/icons/29.png" style="position: absolute;left:5px;top:100%" />
						</div>
						<div id="entries_options" class="gradient_blue" data-formid="<?php echo $form_id; ?>">
							<ul>
								<li>
									<a id="entry_select_field" href="#"><span class="icon-settings"></span>انتخاب فیلدها</a>
								</li>
								<li>
									<div style="border-left: 1px dotted #3B699F;height: 35px;margin-top:5px"></div>
								</li>
								<li>
									<a id="entry_filter" href="#"><span class="icon-binoculars"></span>فیلتر ورودی ها</a>
								</li>
							</ul>
						</div>

						<?php if(!empty($entries_enable_filter)){ ?>
							<div id="filter_info">
								در حال نمایش ورودی های فیلتر شده  <a style="margin-left: 60px" id="me_edit_filter" href="#">ویرایش</a> یا <a href="#" id="me_clear_filter">پاک کردن فیلتر</a>
							</div>
						<?php } ?>
						
						<div style="clear: both"></div>
						<div id="field_selection" style="display: none" class="gradient_blue">
							<h6>انتخاب فیلدهابرای نمایش:</h6>
							<ul>
								<?php 
									foreach($columns_label as $element_name=>$element_label){
										//don't display signature or id field
										if($element_name == 'id' || ($columns_type[$element_name] == 'signature')){
											continue;
										}
										if(!empty($current_column_preference)){
											if(in_array($element_name,$current_column_preference)){
												$checked_tag = 'checked="checked"';
											}else{
												$checked_tag = '';
											}
										}
								?>
									<li>
										<input type="checkbox" value="1" <?php echo $checked_tag; ?> class="element checkbox" name="<?php echo $element_name; ?>" id="<?php echo $element_name; ?>">
										<label for="<?php echo $element_name; ?>" title="<?php echo $element_label; ?>" class="choice"><?php echo $element_label; ?></label>
									</li>
								<?php } ?>
							</ul>
							<div id="field_selection_apply">
									<input type="button" id="me_field_select_submit" value="اعمال تغییرات" class="bb_button bb_mini bb_blue"> <span style="margin-left: 5px" id="cancel_field_select_span">یا <a href="#" id="field_selection_cancel">لغو کردن</a></span>
							</div>
							<img style="position: absolute;right:38px;top:-12px" src="images/icons/29_blue.png" />
						</div>

						<div id="filter_pane" style="display: none" class="gradient_blue">
							
							<h6>نمایش ورودی هایی که مطابقت دارند 
									<select style="margin-left: 5px;margin-right: 5px" name="filter_all_any" id="filter_all_any" class="element select"> 
										<option value="all" <?php if($entries_filter_type == 'all'){ echo 'selected="selected"'; } ?>>همه</option>
										<option value="any" <?php if($entries_filter_type == 'any'){ echo 'selected="selected"'; } ?>>هیچیک</option>
									</select> 
از شرایط زیر:							</h6>
							
							<ul>

								<?php
									if(empty($filter_data)){
										
										if($form_properties['payment_enable_merchant'] == 1){
											$field_labels = array_slice($columns_label, 4);
											$entry_info_labels = array_slice($columns_label, 0,4);
											$payment_info_labels = array_slice($columns_label, -3);

											$field_labels = array_diff($field_labels, $payment_info_labels);
										}else{
											$field_labels = array_slice($columns_label, 4);
											$entry_info_labels = array_slice($columns_label, 0,4);
										}

										$temp_keys = array_keys($field_labels);
										$first_field_element_name = $temp_keys[0];
										$first_field_element_type = $columns_type[$first_field_element_name];
										
										if($first_field_element_type == 'checkbox'){
											if(substr($first_field_element_name, -5) == 'other'){
												$first_field_element_type = 'checkbox_other';
											}
										}

										if(in_array($first_field_element_type, array('money','number'))){
											$condition_text_display = 'display:none';
											$condition_number_display = '';
											$condition_date_display = 'display:none';
											$condition_file_display = 'display:none';
											$condition_checkbox_display = 'display:none';
											$filter_keyword_display = '';
										}else if(in_array($first_field_element_type, array('date','europe_date'))){
											$condition_text_display = 'display:none';
											$condition_number_display = 'display:none';
											$condition_date_display = '';
											$condition_file_display = 'display:none';
											$condition_checkbox_display = 'display:none';
											$filter_keyword_display = '';
											$filter_date_class = 'filter_date';
										}else if($first_field_element_type == 'file'){
											$condition_text_display = 'display:none';
											$condition_number_display = 'display:none';
											$condition_date_display = 'display:none';
											$condition_file_display = '';
											$condition_checkbox_display = 'display:none';
											$filter_keyword_display = '';
										}else if($first_field_element_type == 'checkbox'){
											$condition_text_display = 'display:none';
											$condition_number_display = 'display:none';
											$condition_date_display = 'display:none';
											$condition_file_display = 'display:none';
											$condition_checkbox_display = '';
											$filter_keyword_display = 'display:none';
										}else{
											$condition_text_display = '';
											$condition_number_display = 'display:none';
											$condition_date_display = 'display:none';
											$condition_file_display = 'display:none';
											$condition_checkbox_display = 'display:none';
											$filter_keyword_display = '';
										}

										//prepare the jquery data for the filter list
										$filter_properties = new stdClass();
										$filter_properties->element_name = $first_field_element_name;
										
										if($first_field_element_type == 'file'){
											$filter_properties->condition    = 'شامل';
										}else{
											$filter_properties->condition    = 'است';
										}
										
										$filter_properties->keyword 	 = '';

										$json_filter_properties = json_encode($filter_properties);
										$jquery_data_code .= "\$('#li_1').data('filter_properties',{$json_filter_properties});\n";
								?>

								<li id="li_1" class="filter_settings <?php echo $filter_date_class; ?>">
									<select name="filterfield_1" id="filterfield_1" class="element select condition_fieldname" style="width: 260px"> 
										<optgroup label="فیلدهای فرم">
											<?php
												foreach ($field_labels as $element_name => $element_label) {
													if($columns_type[$element_name] == 'signature'){
														continue;
													}

													if(strlen($element_label) > 40){
														$element_label = substr($element_label, 0, 40).'...';
													}
													
													echo "<option value=\"{$element_name}\">{$element_label}</option>\n";
												}
											?>
										</optgroup>
										<optgroup label="اطلاعات ورودی">
											<?php
												foreach ($entry_info_labels as $element_name => $element_label) {
													echo "<option value=\"{$element_name}\">{$element_label}</option>\n";
												}
											?>
										</optgroup>
										
										<?php if(!empty($payment_info_labels)){ ?>
										<optgroup label="اطلاعات پرداخت">
											<?php
												foreach ($payment_info_labels as $element_name => $element_label) {
													echo "<option value=\"{$element_name}\">{$element_label}</option>\n";
												}
											?>
										</optgroup>
										<?php } ?>
									</select> 
									<select name="conditiontext_1" id="conditiontext_1" class="element select condition_text" style="width: 120px;<?php echo $condition_text_display; ?>">
										<option value="is">است</option>
										<option value="is_not">نیست</option>
										<option value="begins_with">آغاز شده با</option>
										<option value="ends_with">تمام شده با</option>
										<option value="contains">شامل</option>
										<option value="not_contain">شامل نیست</option>
									</select>
									<select name="conditionnumber_1" id="conditionnumber_1" class="element select condition_number" style="width: 120px;<?php echo $condition_number_display; ?>">
										<option value="is">است</option>
										<option value="less_than">کمتر از</option>
										<option value="greater_than">بیشتر از</option>
									</select>
									<select name="conditiondate_1" id="conditiondate_1" class="element select condition_date" style="width: 120px;<?php echo $condition_date_display; ?>">
										<option value="is">است</option>
										<option value="is_before">قبل از</option>
										<option value="is_after">بعد از</option>
									</select>
									<select name="conditionfile_1" id="conditionfile_1" class="element select condition_file" style="width: 120px;<?php echo $condition_file_display; ?>">
										<option value="contains">شامل</option>
										<option value="not_contain">شامل نیست</option>
									</select>
									<select name="conditioncheckbox_1" id="conditioncheckbox_1" class="element select condition_checkbox" style="width: 120px;<?php echo $condition_checkbox_display; ?>">
										<option value="is_one">بررسی شده</option>
										<option value="is_zero">خالی</option>
									</select>
									<input type="text" class="element text filter_keyword" value="" id="filterkeyword_1" style="<?php echo $filter_keyword_display; ?>">
									<input type="hidden" value="" name="datepicker_1" id="datepicker_1">
									<span style="display:none"><img id="datepickimg_1" alt="Pick date." src="images/icons/calendar.png" class="trigger filter_date_trigger" style="vertical-align: top; cursor: pointer" /></span>
									<a href="#" id="deletefilter_1" class="filter_delete_a"><img src="images/icons/51_blue_16.png" /></a>

								</li>

								<?php 
									} else { 
										
										if($form_properties['payment_enable_merchant'] == 1){
											$field_labels = array_slice($columns_label, 4);
											$entry_info_labels = array_slice($columns_label, 0,4);
											$payment_info_labels = array_slice($columns_label, -3);
											
											$field_labels = array_diff($field_labels, $payment_info_labels);
										}else{
											$field_labels = array_slice($columns_label, 4);
											$entry_info_labels = array_slice($columns_label, 0,4);
										}

										$i=1;
										$filter_properties = new stdClass();

										foreach ($filter_data as $value) {
											$field_element_type = $columns_type[$value['element_name']];
											
											if($field_element_type == 'checkbox'){
												if(substr($value['element_name'], -5) == 'other'){
													$field_element_type = 'checkbox_other';
												}
											}

											$filter_date_class = '';
											
											if(in_array($field_element_type, array('money','number'))){
												$condition_text_display = 'display:none';
												$condition_number_display = '';
												$condition_date_display = 'display:none';
												$condition_file_display = 'display:none';
												$condition_checkbox_display = 'display:none';
												$filter_keyword_display = '';
											}else if(in_array($field_element_type, array('date','europe_date'))){
												$condition_text_display = 'display:none';
												$condition_number_display = 'display:none';
												$condition_date_display = '';
												$condition_file_display = 'display:none';
												$condition_checkbox_display = 'display:none';
												$filter_keyword_display = '';
												$filter_date_class = 'filter_date';
											}else if($field_element_type == 'file'){
												$condition_text_display = 'display:none';
												$condition_number_display = 'display:none';
												$condition_date_display = 'display:none';
												$condition_file_display = '';
												$condition_checkbox_display = 'display:none';
												$filter_keyword_display = '';
											}else if($field_element_type == 'checkbox'){
												$condition_text_display = 'display:none';
												$condition_number_display = 'display:none';
												$condition_date_display = 'display:none';
												$condition_file_display = 'display:none';
												$condition_checkbox_display = '';
												$filter_keyword_display = 'display:none';
											}else{
												$condition_text_display = '';
												$condition_number_display = 'display:none';
												$condition_date_display = 'display:none';
												$condition_file_display = 'display:none';
												$condition_checkbox_display = 'display:none';
												$filter_keyword_display = '';
											}

											//prepare the jquery data for the filter list
											$filter_properties->element_name = $value['element_name'];
											$filter_properties->condition    = $value['filter_condition'];
											$filter_properties->keyword 	 = $value['filter_keyword'];

											$json_filter_properties = json_encode($filter_properties);
											$jquery_data_code .= "\$('#li_{$i}').data('filter_properties',{$json_filter_properties});\n";
								?>			

								<li id="li_<?php echo $i; ?>" class="filter_settings <?php echo $filter_date_class; ?>">
									<select name="filterfield_<?php echo $i; ?>" id="filterfield_<?php echo $i; ?>" class="element select condition_fieldname" style="width: 260px"> 
										<optgroup label="فیلدهای فرم">
											<?php
												foreach ($field_labels as $element_name => $element_label) {
													if($columns_type[$element_name] == 'signature'){
														continue;
													}
													
													if($element_name == $value['element_name']){
														$selected_tag = 'selected="selected"';
													}else{
														$selected_tag = '';
													}

													if(strlen($element_label) > 40){
														$element_label = substr($element_label, 0, 40).'...';
													}
													
													echo "<option {$selected_tag} value=\"{$element_name}\">{$element_label}</option>\n";
												}
											?>
										</optgroup>
										<optgroup label="اطلاعات ورودی">
											<?php
												foreach ($entry_info_labels as $element_name => $element_label) {
													if($element_name == $value['element_name']){
														$selected_tag = 'selected="selected"';
													}else{
														$selected_tag = '';
													}

													echo "<option {$selected_tag} value=\"{$element_name}\">{$element_label}</option>\n";
												}
											?>
										</optgroup>
										
										<?php if(!empty($payment_info_labels)){ ?>
										<optgroup label="اطلاعات پرداخت">
											<?php
												foreach ($payment_info_labels as $element_name => $element_label) {
													if($element_name == $value['element_name']){
														$selected_tag = 'selected="selected"';
													}else{
														$selected_tag = '';
													}

													echo "<option {$selected_tag} value=\"{$element_name}\">{$element_label}</option>\n";
												}
											?>
										</optgroup>
										<?php } ?>
									</select> 
									<select name="conditiontext_<?php echo $i; ?>" id="conditiontext_<?php echo $i; ?>" class="element select condition_text" style="width: 120px;<?php echo $condition_text_display; ?>">
										<option <?php if($value['filter_condition'] == 'is'){ echo 'selected="selected"'; } ?> value="is">است</option>
										<option <?php if($value['filter_condition'] == 'is_not'){ echo 'selected="selected"'; } ?> value="is_not">نیست</option>
										<option <?php if($value['filter_condition'] == 'begins_with'){ echo 'selected="selected"'; } ?> value="begins_with">آغاز شده با</option>
										<option <?php if($value['filter_condition'] == 'ends_with'){ echo 'selected="selected"'; } ?> value="ends_with">تمام شده با</option>
										<option <?php if($value['filter_condition'] == 'contains'){ echo 'selected="selected"'; } ?> value="contains">شامل</option>
										<option <?php if($value['filter_condition'] == 'not_contain'){ echo 'selected="selected"'; } ?> value="not_contain">شامل نیست</option>
									</select>
									<select name="conditionnumber_<?php echo $i; ?>" id="conditionnumber_<?php echo $i; ?>" class="element select condition_number" style="width: 120px;<?php echo $condition_number_display; ?>">
										<option <?php if($value['filter_condition'] == 'is'){ echo 'selected="selected"'; } ?> value="is">است</option>
										<option <?php if($value['filter_condition'] == 'less_than'){ echo 'selected="selected"'; } ?> value="less_than">کمتر از</option>
										<option <?php if($value['filter_condition'] == 'greater_than'){ echo 'selected="selected"'; } ?> value="greater_than">بیشتر از</option>
									</select>
									<select name="conditiondate_<?php echo $i; ?>" id="conditiondate_<?php echo $i; ?>" class="element select condition_date" style="width: 120px;<?php echo $condition_date_display; ?>">
										<option <?php if($value['filter_condition'] == 'is'){ echo 'selected="selected"'; } ?> value="is">است</option>
										<option <?php if($value['filter_condition'] == 'is_before'){ echo 'selected="selected"'; } ?> value="is_before">قبل از</option>
										<option <?php if($value['filter_condition'] == 'is_after'){ echo 'selected="selected"'; } ?> value="is_after">بعد از</option>
									</select>
									<select name="conditionfile_<?php echo $i; ?>" id="conditionfile_<?php echo $i; ?>" class="element select condition_file" style="width: 120px;<?php echo $condition_file_display; ?>">
										<option <?php if($value['filter_condition'] == 'contains'){ echo 'selected="selected"'; } ?> value="contains">شامل</option>
										<option <?php if($value['filter_condition'] == 'not_contain'){ echo 'selected="selected"'; } ?> value="not_contain">شامل نیست</option>
									</select>
									<select name="conditioncheckbox_<?php echo $i; ?>" id="conditioncheckbox_<?php echo $i; ?>" class="element select condition_checkbox" style="width: 120px;<?php echo $condition_checkbox_display; ?>">
										<option <?php if($value['filter_condition'] == 'is_one'){ echo 'selected="selected"'; } ?> value="is_one">بررسی شده</option>
										<option <?php if($value['filter_condition'] == 'is_zero'){ echo 'selected="selected"'; } ?> value="is_zero">خالی</option>
									</select>
									<input type="text" class="element text filter_keyword" value="<?php echo htmlspecialchars($value['filter_keyword'],ENT_QUOTES); ?>" id="filterkeyword_<?php echo $i; ?>" style="<?php echo $filter_keyword_display; ?>">
									<input type="hidden" value="" name="datepicker_<?php echo $i; ?>" id="datepicker_<?php echo $i; ?>">
									<span style="display:none"><img id="datepickimg_<?php echo $i; ?>" alt="Pick date." src="images/icons/calendar.png" class="trigger filter_date_trigger" style="vertical-align: top; cursor: pointer" /></span>
									<a href="#" id="deletefilter_<?php echo $i; ?>" class="filter_delete_a"><img src="images/icons/51_blue_16.png" /></a>
								</li>
											
								
									
								<?php 	
										$i++;
										}//end foreach filter_data
									} //end else
								?>

								<li id="li_filter_add" class="filter_add">
									<a href="#" id="filter_add_a"><img src="images/icons/49_blue_16.png" /></a>
								</li>
							</ul>
							<div id="filter_pane_apply">
									<input type="button" id="me_filter_pane_submit" value="اعمال فیلتر" class="bb_button bb_mini bb_blue"> <span id="cancel_filter_pane_span" style="margin-left: 5px">or <a href="#" id="filter_pane_cancel">لغو کردن</a></span>
							</div>
							<img style="position: absolute;right:130px;top:-12px" src="images/icons/29_blue.png" />
						</div>

						<?php 
							$entries_options['page_number']   = $pageno; //set the page number to be displayed
							$entries_options['rows_per_page'] = 15; //set the maximum rows to be displayed each page

							//set the sorting options
							$exploded = explode('-', $sort_by);
							$entries_options['sort_element'] = $exploded[0]; //the element name, e.g. element_2
							$entries_options['sort_order']	 = $exploded[1]; //asc or desc

							//set filter options
							$entries_options['filter_data'] = $filter_data;
							$entries_options['filter_type'] = $entries_filter_type;
							
							//set the column preferences user_id
							$entries_options['column_preferences_user_id'] = $_SESSION['mf_user_id'];

							echo mf_display_entries_table($dbh,$form_id,$entries_options); 
						?>
						
						<div id="me_sort_option">
							<label class="description" for="me_sort_by">مرتب سازی &#8674; </label>
							<select class="element select" id="me_sort_by" name="me_sort_by"> 
								<optgroup label="صعودی">
									<?php 
										foreach ($columns_label as $element_name => $element_label) {

											//don't display signature field
											if($columns_type[$element_name] == 'signature'){
												continue;
											}

											//id is basically the same as date_created, but lot faster for sorting
											if($element_name == 'date_created'){
												$element_name = 'id'; 
											}

											if(strlen($element_label) > 40){
												$element_label = substr($element_label, 0, 40).'...';
											}

											if($sort_by == $element_name.'-asc'){
												$selected_tag = 'selected="selected"';
											}else{
												$selected_tag = '';
											}

											echo "<option {$selected_tag} value=\"{$element_name}-asc\">{$element_label}</option>\n";
										}
									?>
								</optgroup>
								<optgroup label="نزولی">
									<?php 
										foreach ($columns_label as $element_name => $element_label) {

											//don't display signature field
											if($columns_type[$element_name] == 'signature'){
												continue;
											}
											
											//id is basically the same as date_created, but lot faster for sorting
											if($element_name == 'date_created'){
												$element_name = 'id';
												$element_label .= ' (پیش فرض)';
											}

											if(strlen($element_label) > 40){
												$element_label = substr($element_label, 0, 40).'...';
											}

											if($sort_by == $element_name.'-desc'){
												$selected_tag = 'selected="selected"';
											}else{
												$selected_tag = '';
											}

											echo "<option {$selected_tag} value=\"{$element_name}-desc\">{$element_label}</option>\n";
										}
									?>
								</optgroup>
							</select>
						</div>
					
					<?php } else { ?>
						
						<div id="entries_manager_empty">
								<h2>هیچ ورودی ای نیست</h2>
								<h3>این فرم هیچ ورودی ای ندارد</h3>
						</div>	

					<?php } ?>

				</div> <!-- /end of content_body -->	
			
			</div><!-- /.post -->
		</div><!-- /#content -->

<div id="dialog-warning" title="Error Title" class="buttons" style="display: none">
	<span class="icon-bubble-notification"></span> 
	<p id="dialog-warning-msg">
		خطا
	</p>
</div>
<div id="dialog-export-entries" title="Select File Type" class="buttons" style="display: none">
	<ul>
		<li class="gradient_blue"><a id="export_as_excel" href="#" class="export_link">فایل اکسل (.xls)</a></li>
		<li class="gradient_blue"><a id="export_as_csv" href="#" class="export_link">جدا شده با ویرگول (.csv)</a></li>
		<li class="gradient_blue"><a id="export_as_txt" href="#" class="export_link">زبانه جدا (.txt)</a></li>
	</ul>
</div>
<div id="dialog-confirm-entry-delete" title="آیا از حذف این مطلب مطمئن هستید ؟" class="buttons" style="display: none">
	<span class="icon-bubble-notification"></span>
	<p id="dialog-confirm-entry-delete-msg">
		این عمل قابل بازگشت نیست.<br/>
		<strong id="dialog-confirm-entry-delete-info">داده ها و فایل های مرتبط با نوشته انتخاب شده شما حذف خواهد شد.</strong><br/><br/>
		اگر شما مطمئن هستید میتوانید تایید کنید<br /><br />
	</p>				
</div>
 
<?php
	$footer_data =<<<EOT
<script type="text/javascript">
	$(function(){
		{$jquery_data_code}		
    });
</script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.core.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.widget.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.tabs.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.mouse.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.sortable.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.draggable.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.position.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.dialog.js"></script>
<script type="text/javascript" src="js/datepick/jquery.datepick.js"></script>
<script type="text/javascript" src="js/manage_entries.js"></script>
EOT;

	require('includes/footer.php'); 
?>