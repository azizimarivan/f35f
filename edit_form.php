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
	
	require('includes/language.php');
	require('includes/view-functions.php');
	require('includes/users-functions.php');

	
	$dbh = mf_connect_db();
	$mf_settings = mf_get_settings($dbh);
	
	$form_id = (int) trim($_REQUEST['id']);
	$unlock_hash = trim($_REQUEST['unlock']);

	$is_new_form = false;
	
	//check the form_id
	//if blank or zero, create a new form first, otherwise load the form
	if(empty($form_id)){
		$is_new_form = true;
		//insert into ap_forms table and set the status to draft
		//set the status within 'form_active' field
		//form_active: 0 - Inactive / Disabled temporarily
		//form_active: 1 - Active
		//form_active: 2 - Draft
		//form_active: 9 - Deleted

		//check user privileges, is this user has privilege to create new form?
		if(empty($_SESSION['mf_user_privileges']['priv_new_forms'])){
			$_SESSION['MF_DENIED'] = "شما اجازه دسترسی به ایجاد فرم های جدید را ندارید.";

			$ssl_suffix = mf_get_ssl_suffix();						
			header("Location: http{$ssl_suffix}://".$_SERVER['HTTP_HOST'].mf_get_dirname($_SERVER['PHP_SELF'])."/restricted.php");
			exit;
		}


		//generate random form_id number, based on existing value
		$query = "select max(form_id) max_form_id from ".MF_TABLE_PREFIX."forms";
		$params = array();
		
		$sth = mf_do_query($query,$params,$dbh);
		$row = mf_do_fetch_result($sth);
		
		if(empty($row['max_form_id'])){
			$last_form_id = 10000;
		}else{
			$last_form_id = $row['max_form_id'];
		}
		
		$form_id = $last_form_id + rand(100,1000);

		//insert into ap_permissions table, so that this user can add fields
		$query = "insert into ".MF_TABLE_PREFIX."permissions(form_id,user_id,edit_form,edit_entries,view_entries) values(?,?,1,1,1)";
		$params = array($form_id,$_SESSION['mf_user_id']);
		mf_do_query($query,$params,$dbh);
		

		$query = "INSERT INTO `".MF_TABLE_PREFIX."forms` (
							form_id,
							form_name,
							form_description,
							form_redirect,
							form_redirect_enable,
							form_active,
							form_success_message,
							form_password,
							form_frame_height,
							form_unique_ip,
							form_captcha,
							form_captcha_type,
							form_review,
							form_label_alignment,
							form_resume_enable,
							form_limit_enable,
							form_limit,
							form_language,
							form_schedule_enable,
							form_schedule_start_date,
							form_schedule_end_date,
							form_schedule_start_hour,
							form_schedule_end_hour,
							form_lastpage_title,
							form_submit_primary_text,
							form_submit_secondary_text,
							form_submit_primary_img,
							form_submit_secondary_img,
							form_submit_use_image,
							form_page_total,
							form_pagination_type,
							form_review_primary_text,
							form_review_secondary_text,
							form_review_primary_img,
							form_review_secondary_img,
							form_review_use_image,
							form_review_title,
							form_review_description,
							form_custom_script_enable,
							form_custom_script_url 
							)
					VALUES (?,
							'فرم بدون عنوان',
							'این توضیحات فرم شما است. برای ویرایش اینجا را کلیک کنید.',
							'',
							0,
							2,
							'موفقیت آمیز! ارسالی های شما ذخیره شده است!',
							'',
							0,
							0,
							0,
							'r',
							0,
							'top_label',
							0,
							0,
							0,
							'english',
							0,
							'',
							'',
							'',
							'',
							'صفحه بدون عنوان',
							'ارسال',
							'قبلی',
							'',
							'',
							0,
							1,
							'مراحل',
							'ارسال',
							'قبلی',
							'',
							'',
							0,
							'بررسی اطلاعاتتان',
							'لطفا مرور کنید ورودی هایتان را در زیر. و برای پایان بر روی دکمه ارسال کلیک کنید',
							0,
							''
						   );";
		mf_do_query($query,array($form_id),$dbh);
	}else{
		//check permission, is the user allowed to access this page?
		if(empty($_SESSION['mf_user_privileges']['priv_administer'])){
			$user_perms = mf_get_user_permissions($dbh,$form_id,$_SESSION['mf_user_id']);

			//this page need edit_form permission
			if(empty($user_perms['edit_form'])){
				$_SESSION['MF_DENIED'] = "شما اجازه ویرایش این فرم را ندارید.";

				$ssl_suffix = mf_get_ssl_suffix();						
				header("Location: http{$ssl_suffix}://".$_SERVER['HTTP_HOST'].mf_get_dirname($_SERVER['PHP_SELF'])."/restricted.php");
				exit;
			}
		}

		$is_form_locked = false;

		//get lock status for this form
		$query = "select lock_date from ".MF_TABLE_PREFIX."form_locks where form_id = ? and user_id <> ?";
		$params = array($form_id,$_SESSION['mf_user_id']);
	
		$sth = mf_do_query($query,$params,$dbh);
		$row = mf_do_fetch_result($sth);		

		if(!empty($row['lock_date'])){
			$lock_date = strtotime($row['lock_date']);
			$current_date = date(time());
			
			$seconds_diff = $current_date - $lock_date;
			$lock_expiry_time = 60 * 60; //1 hour expiry
			
			//if there is a lock and the lock hasn't expired yet
			if($seconds_diff < $lock_expiry_time){
				$is_form_locked = true;
			}
		}

		//if the form is locked and no unlock key, redirect to warning page
		if($is_form_locked === true && empty($unlock_hash)){
			$ssl_suffix = mf_get_ssl_suffix();						
			
			header("Location: http{$ssl_suffix}://".$_SERVER['HTTP_HOST'].mf_get_dirname($_SERVER['PHP_SELF'])."/form_locked.php?id=".$form_id);
			exit;
		}

		//if this is an existing form, delete the previous unsaved form fields
		$query = "DELETE FROM `".MF_TABLE_PREFIX."form_elements` where form_id = ? AND element_status='2'";
		$params = array($form_id);
		mf_do_query($query,$params,$dbh);
		
		//the ap_element_options table has "live" column, which has 3 possible values:
		// 0 - the option is being deleted
		// 1 - the option is active
		// 2 - the option is currently being drafted, not being saved yet and will be deleted by edit_form.php if the form is being edited the next time
		$query = "DELETE FROM `".MF_TABLE_PREFIX."element_options` where form_id = ? AND live='2'";
		$params = array($form_id);
		mf_do_query($query,$params,$dbh);

		//lock this form, to prevent other user editing the same form at the same time
		$query = "delete from ".MF_TABLE_PREFIX."form_locks where form_id=?";
		$params = array($form_id);
		mf_do_query($query,$params,$dbh);

		
		$new_lock_date = date("Y-m-d H:i:s");
		$query = "insert into ".MF_TABLE_PREFIX."form_locks(form_id,user_id,lock_date) values(?,?,?)";
		$params = array($form_id,$_SESSION['mf_user_id'],$new_lock_date);
		mf_do_query($query,$params,$dbh);

	}
	//get the HTML markup of the form
	$markup = mf_display_raw_form($dbh,$form_id);
	
	//get the properties for each form field
	//get form data
	$query 	= "select 
					 form_name,
					 form_active,
					 form_description,
					 form_redirect,
					 form_redirect_enable,
					 form_success_message,
					 form_password,
					 form_unique_ip,
					 form_captcha,
					 form_captcha_type,
					 form_review,
					 form_resume_enable,
					 form_limit_enable,
					 form_limit,
					 form_language,
					 form_frame_height,
					 form_label_alignment,
					 form_lastpage_title,
					 form_schedule_enable,
					 form_schedule_start_date,
					 form_schedule_end_date,
					 form_schedule_start_hour,
					 form_schedule_end_hour,
					 form_submit_primary_text,
					 form_submit_secondary_text,
					 form_submit_primary_img,
					 form_submit_secondary_img,
					 form_submit_use_image,
					 form_page_total,
					 form_pagination_type,
					 form_review_primary_text,
					 form_review_secondary_text,
					 form_review_primary_img,
					 form_review_secondary_img,
					 form_review_use_image,
					 form_review_title,
					 form_review_description,
					 form_custom_script_enable,
					 form_custom_script_url 
			     from 
			     	 ".MF_TABLE_PREFIX."forms 
			    where 
			    	 form_id = ?";
	$params = array($form_id);
	
	$sth = mf_do_query($query,$params,$dbh);
	$row = mf_do_fetch_result($sth);
	
	$form = new stdClass();
	if(!empty($row)){
		$form->id 				= $form_id;
		$form->name 			= $row['form_name'];
		$form->active 			= (int) $row['form_active'];
		$form->description 		= $row['form_description'];
		$form->redirect 		= $row['form_redirect'];
		$form->redirect_enable 	= (int) $row['form_redirect_enable'];
		$form->success_message  = $row['form_success_message'];
		$form->password 		= $row['form_password'];
		$form->frame_height 	= $row['form_frame_height'];
		$form->unique_ip 		= (int) $row['form_unique_ip'];
		$form->captcha 			= (int) $row['form_captcha'];
		$form->captcha_type 	= $row['form_captcha_type'];
		$form->review 			= (int) $row['form_review'];
		$form->resume_enable 	= (int) $row['form_resume_enable'];
		$form->limit_enable 	= (int) $row['form_limit_enable'];
		$form->limit 			= (int) $row['form_limit'];
		$form->label_alignment	= $row['form_label_alignment'];
		$form->schedule_enable 	= (int) $row['form_schedule_enable'];
		
		if(empty($row['form_language'])){
			$form->language		= 'english';
		}else{
			$form->language		= $row['form_language'];
		}
		
		$form->schedule_start_date  = $row['form_schedule_start_date'];
		if(!empty($row['form_schedule_start_hour'])){
			$form->schedule_start_hour  = date('h:i:a',strtotime($row['form_schedule_start_hour']));
		}else{
			$form->schedule_start_hour  = '';
		}
		$form->schedule_end_date  	= $row['form_schedule_end_date'];
		if(!empty($row['form_schedule_end_hour'])){
			$form->schedule_end_hour  	= date('h:i:a',strtotime($row['form_schedule_end_hour']));
		}else{
			$form->schedule_end_hour	= '';
		}
		$form_lastpage_title		= $row['form_lastpage_title'];
		$form_submit_primary_text 	= $row['form_submit_primary_text'];
		$form_submit_secondary_text = $row['form_submit_secondary_text'];
		$form_submit_primary_img 	= $row['form_submit_primary_img'];
		$form_submit_secondary_img  = $row['form_submit_secondary_img'];
		$form_submit_use_image  	= (int) $row['form_submit_use_image'];
		$form->page_total			= (int) $row['form_page_total'];
		$form->pagination_type		= $row['form_pagination_type'];
		
		$form->review_primary_text 	 = $row['form_review_primary_text'];
		$form->review_secondary_text = $row['form_review_secondary_text'];
		$form->review_primary_img 	 = $row['form_review_primary_img'];
		$form->review_secondary_img  = $row['form_review_secondary_img'];
		$form->review_use_image  	 = (int) $row['form_review_use_image'];
		$form->review_title			 = $row['form_review_title'];
		$form->review_description	 = $row['form_review_description'];
		$form->custom_script_enable  = (int) $row['form_custom_script_enable'];
		$form->custom_script_url  	 = $row['form_custom_script_url'];
	} 
	
	//get element options first and store it into array
	$query = "select 
					element_id,
					option_id,
					`position`,
					`option`,
					option_is_default 
			    from 
			    	".MF_TABLE_PREFIX."element_options 
			   where 
			   		form_id = ? and live=1 
			order by 
					element_id asc,`position` asc";
	$params = array($form_id);
	$sth 	= mf_do_query($query,$params,$dbh);
	
	while($row = mf_do_fetch_result($sth)){
		$element_id = $row['element_id'];
		$option_id  = $row['option_id'];
		$options_lookup[$element_id][$option_id]['position'] 		  = $row['position'];
		$options_lookup[$element_id][$option_id]['option'] 			  = $row['option'];
		$options_lookup[$element_id][$option_id]['option_is_default'] = $row['option_is_default'];
	}
	
	//get the last option id for each options and store it into array
	//we need it when the user adding a new option, so that we could assign the last option id + 1
	$query = "select 
					element_id,
					max(option_id) as last_option_id 
			    from 
			    	".MF_TABLE_PREFIX."element_options 
			   where 
			   		form_id = ? 
			group by 
					element_id";
	$params = array($form_id);
	$sth 	= mf_do_query($query,$params,$dbh);
	
	while($row = mf_do_fetch_result($sth)){
		$element_id = $row['element_id'];
		$last_option_id_lookup[$element_id] = $row['last_option_id'];
	}

	
	//get elements data
	$element = array();
	$query = "select 
					element_id,
					element_title,
					element_guidelines,
					element_size,
					element_is_required,
					element_is_unique,
					element_is_private,
					element_type,
					element_position,
					element_default_value,
					element_constraint,
					element_css_class,
					element_range_min,
					element_range_max,
					element_range_limit_by,
					element_choice_columns,
					element_choice_has_other,
					element_choice_other_label,
					element_time_showsecond, 
					element_time_24hour,
					element_address_hideline2,
					element_address_us_only,
					element_date_enable_range,
					element_date_range_min,
					element_date_range_max,
					element_date_enable_selection_limit,
					element_date_selection_max,
					element_date_disable_past_future,
					element_date_past_future,
					element_date_disable_weekend,
					element_date_disable_specific,
					element_date_disabled_list,
					element_file_enable_type_limit,
					element_file_block_or_allow,
					element_file_type_list,
					element_file_as_attachment,
					element_file_enable_advance,
					element_file_auto_upload,
					element_file_enable_multi_upload,
					element_file_max_selection,
					element_file_enable_size_limit,
					element_file_size_max,
					element_submit_use_image,
					element_submit_primary_text,
					element_submit_secondary_text,
					element_submit_primary_img,
					element_submit_secondary_img,
					element_page_title,
					element_matrix_allow_multiselect,
					element_matrix_parent_id,
					element_section_display_in_email,
					element_section_enable_scroll,
					element_number_enable_quantity,
					element_number_quantity_link 
				from 
					".MF_TABLE_PREFIX."form_elements 
			   where 
			   		form_id = ? and element_status='1'
			order by 
					element_position asc";
	$params = array($form_id);
	$sth 	= mf_do_query($query,$params,$dbh);
	
	$j=0;
	while($row = mf_do_fetch_result($sth)){
		$element_id = $row['element_id'];
		
		//lookup element options first
		$option_id_array = array();
		$element_options = array();
		
		if(!empty($options_lookup[$element_id])){
			
			$i=1;
			foreach ($options_lookup[$element_id] as $option_id=>$data){
				$element_options[$option_id] = new stdClass();
				$element_options[$option_id]->position 	 = $i;
				$element_options[$option_id]->option 	 = $data['option'];
				$element_options[$option_id]->is_default = $data['option_is_default'];
				$element_options[$option_id]->is_db_live = 1;
				
				$option_id_array[$element_id][$i] = $option_id;
				
				$i++;
			}
		}
		
	
		//populate elements
		$element[$j] = new stdClass();
		$element[$j]->title 		= $row['element_title'];
		$element[$j]->guidelines 	= $row['element_guidelines'];
		$element[$j]->size 			= $row['element_size'];
		$element[$j]->is_required 	= $row['element_is_required'];
		$element[$j]->is_unique 	= $row['element_is_unique'];
		$element[$j]->is_private 	= $row['element_is_private'];
		$element[$j]->type 			= $row['element_type'];
		$element[$j]->position 		= $row['element_position'];
		$element[$j]->id 			= $row['element_id'];
		$element[$j]->is_db_live 	= 1;
		$element[$j]->default_value = $row['element_default_value'];
		$element[$j]->constraint 	= $row['element_constraint'];
		$element[$j]->css_class 	= $row['element_css_class'];
		$element[$j]->range_min 	= (int) $row['element_range_min'];
		$element[$j]->range_max 	= (int) $row['element_range_max'];
		$element[$j]->range_limit_by	 = $row['element_range_limit_by'];
		$element[$j]->choice_columns	 = (int) $row['element_choice_columns'];
		$element[$j]->choice_has_other	 = (int) $row['element_choice_has_other'];
		$element[$j]->choice_other_label = $row['element_choice_other_label'];
		$element[$j]->time_showsecond	 = (int) $row['element_time_showsecond'];
		$element[$j]->time_24hour	 	 = (int) $row['element_time_24hour'];
		$element[$j]->address_hideline2	 = (int) $row['element_address_hideline2'];
		$element[$j]->address_us_only	 = (int) $row['element_address_us_only'];
		$element[$j]->date_enable_range	 = (int) $row['element_date_enable_range'];
		$element[$j]->date_range_min	 = $row['element_date_range_min'];
		$element[$j]->date_range_max	 = $row['element_date_range_max'];
		$element[$j]->date_enable_selection_limit	= (int) $row['element_date_enable_selection_limit'];
		$element[$j]->date_selection_max	 		= (int) $row['element_date_selection_max'];
		$element[$j]->date_disable_past_future	 	= (int) $row['element_date_disable_past_future'];
		$element[$j]->date_past_future	 			= $row['element_date_past_future'];
		$element[$j]->date_disable_weekend	 		= (int) $row['element_date_disable_weekend'];
		$element[$j]->date_disable_specific	 		= (int) $row['element_date_disable_specific'];
		$element[$j]->date_disabled_list	 		= $row['element_date_disabled_list'];					
		$element[$j]->file_enable_type_limit	 	= (int) $row['element_file_enable_type_limit'];						
		$element[$j]->file_block_or_allow	 		= $row['element_file_block_or_allow'];
		$element[$j]->file_type_list	 			= $row['element_file_type_list'];
		$element[$j]->file_as_attachment	 		= (int) $row['element_file_as_attachment'];	
		$element[$j]->file_enable_advance	 		= (int) $row['element_file_enable_advance'];	
		$element[$j]->file_auto_upload	 			= (int) $row['element_file_auto_upload'];
		$element[$j]->file_enable_multi_upload	 	= (int) $row['element_file_enable_multi_upload'];
		$element[$j]->file_max_selection	 		= (int) $row['element_file_max_selection'];
		$element[$j]->file_enable_size_limit	 	= (int) $row['element_file_enable_size_limit'];
		$element[$j]->file_size_max	 				= (int) $row['element_file_size_max'];
		$element[$j]->submit_use_image	 			= (int) $row['element_submit_use_image'];
		$element[$j]->submit_primary_text	 		= $row['element_submit_primary_text'];
		$element[$j]->submit_secondary_text	 		= $row['element_submit_secondary_text'];
		$element[$j]->submit_primary_img	 		= $row['element_submit_primary_img'];
		$element[$j]->submit_secondary_img	 		= $row['element_submit_secondary_img'];
		$element[$j]->page_title	 				= $row['element_page_title'];
		$element[$j]->matrix_allow_multiselect	 	= (int) $row['element_matrix_allow_multiselect'];
		$element[$j]->matrix_parent_id	 			= (int) $row['element_matrix_parent_id'];
		$element[$j]->section_display_in_email	 	= (int) $row['element_section_display_in_email'];
		$element[$j]->section_enable_scroll	 		= (int) $row['element_section_enable_scroll'];
		$element[$j]->number_enable_quantity	 	= (int) $row['element_number_enable_quantity'];
		$element[$j]->number_quantity_link	 		= $row['element_number_quantity_link'];
						 
		
		if(!empty($element_options)){
			$element[$j]->options 	= $element_options;
			$element[$j]->last_option_id = $last_option_id_lookup[$element_id];
		}else{
			$element[$j]->options 	= '';
		}
		
		//if the element is a matrix field and not the parent, store the data into a lookup array for later use when rendering the markup
		if($row['element_type'] == 'matrix' && !empty($row['element_matrix_parent_id'])){
				
				$parent_id 	  = $row['element_matrix_parent_id'];
				$row_position = count($matrix_elements[$parent_id]) + 2;
				$element_id   = $row['element_id'];
				
				$matrix_elements[$parent_id][$element_id] = new stdClass();
				$matrix_elements[$parent_id][$element_id]->is_db_live = 1;
				$matrix_elements[$parent_id][$element_id]->position   = $row_position;
				$matrix_elements[$parent_id][$element_id]->row_title  = $row['element_title'];
				
				$column_data = array();
				$col_position = 1;
				foreach ($element_options as $option_id=>$value){
					$column_data[$option_id] = new stdClass();
					$column_data[$option_id]->is_db_live = 1;
					$column_data[$option_id]->position 	 = $col_position;
					$column_data[$option_id]->column_title 	= $value->option;
					$col_position++;
				}
				
				$matrix_elements[$parent_id][$element_id]->column_data = $column_data;
				
				//remove it from the main element array
				$element[$j] = array();
				unset($element[$j]);
				$j--;
		}
		
		$j++;
	}
	
	//if this is multipage form, add the lastpage submit property into the element list
	if($form->page_total > 1){
		$element[$j] = new stdClass();
		$element[$j]->id 		 = 'lastpage';
		$element[$j]->type 		 = 'page_break';
		$element[$j]->page_title = $form_lastpage_title;
		$element[$j]->submit_primary_text	 		= $form_submit_primary_text;
		$element[$j]->submit_secondary_text	 		= $form_submit_secondary_text;
		$element[$j]->submit_primary_img	 		= $form_submit_primary_img;
		$element[$j]->submit_secondary_img	 		= $form_submit_secondary_img;
		$element[$j]->submit_use_image	 			= $form_submit_use_image;
	}

		
	$jquery_data_code = '';
	
	//build the json code for form fields
	$all_element = array('elements' => $element);
	foreach ($element as $data){
		//if this is matrix element, attach the children data into options property and merge with current (matrix parent) options
		if($data->type == 'matrix'){
			$matrix_elements[$data->id][$data->id] = new stdClass();
			$matrix_elements[$data->id][$data->id]->is_db_live = 1;
			$matrix_elements[$data->id][$data->id]->position   = 1;
			$matrix_elements[$data->id][$data->id]->row_title  = $data->title;
				
			$column_data = array();
			$col_position = 1;
			foreach ($data->options as $option_id=>$value){
				$column_data[$option_id] = new stdClass();
				$column_data[$option_id]->is_db_live = 1;
				$column_data[$option_id]->position 	 = $col_position;
				$column_data[$option_id]->column_title 	= $value->option;
				$col_position++;
			}
				
			$matrix_elements[$data->id][$data->id]->column_data = $column_data;

			$temp_array = array();
			$temp_array = $matrix_elements[$data->id];
			
			asort($temp_array);
			
			$matrix_elements[$data->id] = array();
			$matrix_elements[$data->id] = $temp_array;
			
			$data->options = array();
			$data->options = $matrix_elements[$data->id];
			
		}
		$field_settings = json_encode($data);
		$jquery_data_code .= "\$('#li_{$data->id}').data('field_properties',{$field_settings});\n";
	}

	
	//build the json code for form settings
	$json_form = json_encode($form);
	$jquery_data_code .= "\$('#form_header').data('form_properties',{$json_form});\n";
	
	
	
	$header_data =<<<EOT
<link type="text/css" href="js/jquery-ui/themes/base/jquery.ui.all.css" rel="stylesheet" />
<link type="text/css" href="css/edit_form.css" rel="stylesheet" />
<link type="text/css" href="js/datepick/smoothness.datepick.css" rel="stylesheet" />
<link rel="stylesheet" href="js/datepicker/persian-datepicker.min.css"/>
EOT;
	
	$current_nav_tab = 'manage_forms';
	
	require('includes/header.php'); 
?>

 		<div id="editor_loading">
 			در حال بارگزاری ... لطفا صبر نمایید ...
 		</div>
		
		<div id="content">
		<div class="post form_editor">
		<span id="selected_field_image" class="icon-arrow-right2 arrow-field-prop" ></span> 
		
<?php 
	echo $markup;
	
?>

		<div id="bottom_bar" style="display: none">
				<div class="bottom_bar_side">
					<img style="float: left" src="images/bullet_green.png" />
					<img style="float: right" src="images/bullet_green.png"/>
				</div>
				<div id="bottom_bar_content" class="buttons_bar">
						 
						<a id="bottom_bar_save_form" href="#" class="bb_button bb_green"  alt="ذخیره فرم" title="ذخیره فرم" >
					        <span class="icon-disk"></span>ذخیره فرم
					    </a>
					    
					    <a id="bottom_bar_add_field" class="bb_button bb_grey" href="#" alt="اضافه کردن فیلد جدید" title="اضافه کردن فیلد جدید">
					       <span class="icon-plus-circle"></span>اضافه کردن فیلد
					    </a>
					    <div id="bottom_bar_field_action">
						  	<span class="icon-arrow-right2 arrow-field-prop" ></span> 
						    <a id="bottom_bar_duplicate_field" href="#" class="bb_button bb_grey" alt="تکرار فیلد انتخاب شده" title="فیلد تکرار انتخاب شده">
						       <span class="icon-copy"></span>تکرار
						    </a>
						    
						    <a id="bottom_bar_delete_field" href="#" class="bb_button bb_red" alt="حذف فیلد انتخاب شده" title="حذف فیلد انتخاب شده">
						        <span class="icon-remove"></span>حذف
						    </a>
					   </div> 
				</div>
				<div id="bottom_bar_loader">
					<span>
						<img src="images/loader.gif" width="32" height="32"/>
						<span id="bottom_bar_msg">لطفا صبر کنید ... در حال همگام سازی ...</span>
					</span>
				</div>
				<div class="bottom_bar_side">
					<img style="float: left" src="images/bullet_green.png" />
					<img style="float: right" src="images/bullet_green.png"/>
				</div>
		</div>	
		<div id="bottom_bar_limit"></div>
<?php if($is_new_form){ ?>		
		<div id="no_fields_notice">
			<span class="icon-arrow-right" style="margin-bottom: 20px;color: #529214;font-size: 50px;display: block"></span>
			<h3>فرم شما هنوز هیچ فیلدی ندارد</h3>
			<p><span style="color: #529214; font-weight: bold;"> با کلیک بر روی دکمه های</span> نوار کناری چپ و <span style="color: #529214; font-weight: bold;"> کشیدن آن به اینجا و رها کردن آن</span> میتوانید فیلدی اضافه کنید</p>
		</div>			
<?php } ?>        
        </div>   	
			 
		</div><!-- /#content -->
		
<div id="sidebar">
			<div id="builder_tabs">
								<ul id="builder_tabs_btn" style="display: none">
									<li id="btn_add_field"><a href="#tab_add_field"><font style="font-family:B Mitra; font-size:16px;">اضافه کردن یک فیلد</font></a></li>
									<li id="btn_field_properties"><a href="#tab_field_properties"><font style="font-family:B Mitra; font-size:16px;">تنظیمات فیلد</font></a></li>
									<li id="btn_form_properties"><a href="#tab_form_properties"><font style="font-family:B Mitra; font-size:16px;">تنظیمات فرم</font></a></li>
								</ul>
								<div id="tab_add_field">
									<div id="social" class="box">
										<ul>   		
											<li id="btn_single_line_text" class="box">
												<a id="a_single_line_text" href="#" title="تنها خط متنی">
													<span class="icon-font-size icon-font"></span><span class="blabel">تنها خط متنی</span>
												</a>
											</li>     
											  
											<li id="btn_number" class="box">
												<a id="a_number" href="#" title="عدد">
													<span class="icon-seven-segment8 icon-font"></span><span class="blabel">عدد</span>
												</a>
											</li>     
								          	
								          	<li id="btn_paragraph_text" class="box">
												<a id="a_paragraph_text" href="#" title="متن پاراگرافی">
													<span class="icon-paragraph-left icon-font"></span><span class="blabel">متن پاراگرافی</span>
												</a>
											</li>     
											<li id="btn_checkboxes" class="box">
												<a id="a_checkboxes" href="#" title="چک باکس">
													<span class="icon-checkbox icon-font"></span><span class="blabel">چک باکس</span>
												</a>
											</li>   	
											
											<li id="btn_multiple_choice" class="box">
												<a id="a_multiple_choice" href="#" title="انتخاب چندگانه">
													<span class="icon-list icon-font"></span><span class="blabel">انتخاب چندگانه</span>
												</a>
											</li>     
											  
											<li id="btn_drop_down" class="box">
												<a id="a_drop_down" href="#" title="کشویی">
													<span class="icon-menu icon-font"></span><span class="blabel">کشویی</span>
												</a>
											</li>     
								          	
								          	<li id="btn_name" class="box">
												<a id="a_name" href="#" title="نام">
													<span class="icon-user2 icon-font"></span><span class="blabel">نام</span>
												</a>
											</li>     
											<li id="btn_date" class="box">
												<a id="a_date" href="#" title="تاریخ میلادی">
													<span class="icon-calendar icon-font"></span><span class="blabel">تاریخ میلادی</span>
												</a>
											</li>   	
											
											<li id="btn_time" class="box">
												<a id="a_time" href="#" title="زمان">
													<span class="icon-alarm icon-font"></span><span class="blabel">زمان<br />Time</span>
												</a>
											</li>     
											  
											<li id="btn_phone" class="box">
												<a id="a_phone" href="#" title="تاریخ شمسی کد رهگیری">
													<span class="icon-phone icon-font"></span><span class="blabel">تاریخ شمسی<br />کد رهگیری</span>
												</a>
											</li>     
								          	
								          	<li id="btn_address" class="box">
												<a id="a_address" href="#" title="آدرس">
													<span class="icon-home icon-font"></span><span class="blabel">آدرس</span>
												</a>
											</li>     
											<li id="btn_website" class="box">
												<a id="a_website" href="#" title="وبسایت">
													<span class="icon-link icon-font"></span><span class="blabel">وبسایت</span>
												</a>
											</li>   	
											
											<li id="btn_price" class="box">
												<a id="a_price" href="#" title="قیمت">
													<span class="icon-coins icon-font"></span><span class="blabel">قیمت</span>
												</a>
											</li>     
											  
											<li id="btn_email" class="box">
												<a  id="a_email" href="#" title="ایمیل">
													<span class="icon-envelope-opened icon-font"></span><span class="blabel">ایمیل</span>
												</a>
											</li>     
								          	
								          	<li id="btn_matrix" class="box">
												<a id="a_matrix" href="#" title="انتخاب ماتریسی">
													<span class="icon-grid icon-font"></span><span class="blabel">انتخاب ماتریسی</span>
												</a>
											</li>     
											<li id="btn_file_upload" class="box">
												<a id="a_file_upload" href="#" title="آپلود فایل">
													<span class="icon-file-upload icon-font"></span><span class="blabel">آپلود فایل</span>
												</a>
											</li>  
											<li id="btn_section_break" class="box">
												<a id="a_section_break" href="#" title="برش مقطعی">
													<span class="icon-marker icon-font"></span><span class="blabel">برش مقطعی</span>
												</a>
											</li>     
											<li id="btn_page_break" class="box">
												<a id="a_page_break" href="#" title="برش صفحه">
													<span class="icon-file-plus icon-font"></span><span class="blabel">برش صفحه</span>
												</a>
											</li>   	
											<li id="btn_signature" class="box">
												<a id="a_signature" href="#" title="ءامضا">
													<span class="icon-quill icon-font"></span><span class="blabel">امضاء</span>
												</a>
											</li>
											        			
					</ul>
										
					<div class="clear"></div>
									
			</div><!-- /#social -->
		</div>
				
		<div id="tab_field_properties" style="display: none">
			<div id="field_properties_pane" class="box"> <!-- Start field properties pane -->
				<form style="display: block;" id="element_properties" action="" onsubmit="return false;">
					
					<div id="element_inactive_msg">
						<div class="bullet_bar_top">
							<img class="left" src="images/bullet_green.png" />
							<img class="right" src="images/bullet_green.png"/>
						</div>
						
						<span class="icon-arrow-left" style="margin-top: 80px;color: #529214;font-size: 50px;display: block"></span>
						<h3>لطفا یک فیلد انتخاب کنید</h3>
						<p id="eim_p">کلیک بر روی فیلد در راست و تغییر خواص آن</p>
						 
						<div class="bullet_bar_bottom">
							<img class="left" src="images/bullet_green.png" />
							<img class="right" src="images/bullet_green.png"/>
						</div>
					</div>
					
					<div id="element_properties_form">
						<div class="bullet_bar_top">
							<img class="left" src="images/bullet_green.png" />
							<img class="right" src="images/bullet_green.png"/>
						</div>
						<div class="num" id="element_position">12</div>
						<ul id="all_properties">
						<li id="prop_element_label">
								<label class="desc" for="element_label">بر چسب فیلد <img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="برچسب فیلد یک یا دو کلمه میباشد که مستقیما بالای فیلد قرار دارد"/>
								</label>
								<textarea id="element_label" name="element_label" class="textarea" /></textarea>
						</li>
						
						<li class="leftCol" id="prop_element_type">
							<label class="desc" for="element_type">
							 نوع فیلد
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="نوع فیلد تعیین میکند که چه نوع اطلاعاتی جمع آوری میشود توسط فیلد شما.بعد از ذخیره فرم نمیتوانید نوع فیلد را تغییر دهید."/>
							</label>
							<select class="select full" id="element_type" name="element_type" autocomplete="off" tabindex="12">
							<option value="text">تنها خط متنی</option>
							<option value="textarea">پاراگراف متنی</option>
							<option value="radio">انتخاب چندگانه</option>
							<option value="checkbox">چک باکس</option>
							<option value="select">کشویی</option>
							<option value="number">عدد</option>
							<option value="simple_name">نام</option>
							<option value="date">تاریخ میلادی</option>
							<option value="time">زمان<br />Time</option>
							<option value="phone">تاریخ شمسی<br />کد رهگیری</option>
							<option value="money">قیمت</option>
							<option value="url">وبسایت</option>
							<option value="email">ایمیل</option>
							<option value="address">آدرس</option>
							<option value="file">آپلود فایل</option>
							<option value="section">برش مقطعی</option>
							<option value="matrix">انتخاب ماتریس</option>
							</select>
						</li>
						
						<li class="rightCol" id="prop_element_size">
							<label class="desc" for="element_size">
							سایز فیلد 
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="این ویژگی فقط ظاهر فیلد را تغییر میدهد و میزان داده هایی که توسط این فیلد جمع آوردی میشود را نه کاهش میدهد و نه افزایش."/>
							</label>
							<select class="select full" id="element_size" autocomplete="off" tabindex="13">
							<option value="small">کوچک</option>
							<option value="medium">متوسط</option>
							<option value="large">بزرگ</option>
							</select>
						</li>
						
						<li class="rightCol" id="prop_choice_columns">
							<label class="desc" for="element_choice_columns">
							ستون انتخاب 
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="تنظیم تعداد ستون هایی که برای نمایش استفاده میشود را انتخاب میکند.ستون های درون خطی یعنی انتخابهایی که کنار یکدیگر قرار دارند."/>
							</label>
							<select class="select full" id="element_choice_columns" autocomplete="off">
							<option value="1">یک ستون</option>
							<option value="2">دو ستون</option>
							<option value="3">سه ستون</option>
							<option value="9">درون خطی</option>
							</select>
						</li>
						
						<li class="rightCol" id="prop_date_format">
							<label class="desc" for="field_size">
						  نوع تاریخ 
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="شما میتوانید تغییر دهید تاریخ را بین تاریخ آمریکا و تاریخ اروپا"/>
							</label>
							<select class="select full" id="date_type" autocomplete="off">
							<option id="element_date" value="date">ماه / روز / سال</option>
							<option id="element_europe_date" value="europe_date">روز / ماه / سال</option>
							</select>
						</li>
						
						<li class="rightCol" id="prop_name_format">
							<label class="desc" for="name_format">
						 نوع نام 
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="دو نوع در دسترس است.یک فیلد نام نرمال ، و یا یک فیلد عنوان دار با عنوان و پسوند."/>
							</label>
							<select class="select full" id="name_format" autocomplete="off">
							<option id="element_simple_name" value="simple_name" selected="selected">نرمال</option>
							<option id="element_name" value="name" selected="selected">نرمال + عنوان</option>
							<option id="element_simple_name_wmiddle" value="simple_name_wmiddle" selected="selected">کامل</option>
							<option id="element_name_wmiddle" value="name_wmiddle">کامل + عنوان</option>
							</select>
						</li>
						
						<li class="rightCol" id="prop_phone_format">
							<label class="desc" for="field_size">
							تاریخ شمسی<br />کد رهگیری 
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="شما میتوانید با انتخاب گزینه تاریخ شمسی یک فیلد با نوع تاریخ شمسی ایجاد کنید و همچنین با انتخاب گزینه کد رهگیری میتوانید برای فرم خود یک کد رهگیری نیز ایجاد نمایید.پیشنهاد میشود همیشه برای ایجاد کد رهگیری آن را در انتهای فرم خود قرار دهید"/>
							</label>
<select class="select full" id="phone_format" name="phone_format" autocomplete="off">
							<option id="element_phone" value="phone">تاریخ شمسی</option>
							<option id="element_simple_phone" value="simple_phone">کد رهگیری</option>
							</select>
						</li>
						
						<li class="rightCol" id="prop_currency_format">
							<label class="desc" for="field_size">
							نوع ارز
							</label>
							<select class="select full" id="money_format" name="money_format" autocomplete="off">
							<option id="element_money_usd" value="dollar">&#36; - دلار</option>
							<option id="element_money_euro" value="euro">&#8364; - یورو</option>
							<option id="element_money_pound" value="pound">&#163; - پوند</option>
							<option id="element_money_yen" value="yen">&#165; - ین</option>
							<option id="element_money_baht" value="baht">&#3647; - بات</option>
							<option id="element_money_forint" value="forint">&#70;&#116; - فورینت</option>
							<option id="element_money_franc" value="franc">CHF - فرانک</option>
							<option id="element_money_koruna" value="koruna">&#75;&#269; - کرون</option>
							<option id="element_money_krona" value="krona">kr - مسکوك ایسلند و سوئد</option>
							<option id="element_money_pesos" value="pesos">&#36; - پزو</option>
							<option id="element_money_rand" value="rand">R - رند</option>
							<option id="element_money_ringgit" value="ringgit">RM - رینگیت</option>
							<option id="element_money_rupees" value="rupees">Rs - روپیه</option>
							<option id="element_money_zloty" value="zloty">&#122;&#322; - Złoty</option>
							<option id="element_money_riyals" value="riyals">&#65020; - ریال</option>
							</select>
						</li>
						
						<li class="clear" id="prop_choices">
							<fieldset class="choices">
							<legend>
							گزینه ها 
							<img class="helpmsg" src="images/icons/help3.png" style="vertical-align: top; " title="استفاده کردن از دکمه مثبت و منفی برای اضافه و کم کردن انتخابها . کلیک کردن روی انتخاب و درست کردن آن برای حالت پیش فرض"/>
							</legend>
							<ul id="element_choices">
							<li>
								<input type="radio" title="انتخاب این گزینه برای حالت پیش فرض" class="choices_default" name="choices_default" />
								<input type="text" value="تنظیمات ابتدا" autocomplete="off" class="text" id="choice_1" /> 
								<img title="اضافه کردن" alt="اضافه کردن" src="images/icons/add.png" style="vertical-align: middle" > 
								<img title="حذف" alt="حذف" src="images/icons/delete.png" style="vertical-align: middle" > 
							</li>	
							</ul>
							
							<div style="text-align: center;padding-top: 5px;padding-bottom: 10px">
								<img src="images/icons/page_go.png" style="vertical-align: top"/> <a href="#" id="bulk_import_choices">قرار دادن حجم انتخاب</a>
							</div> 
							
							</fieldset>
							
						</li>
						
						<li class="clear" id="prop_choices_other">
							<fieldset class="choices">
							<legend>
تنظیمات انتخابها							</legend>
							</legend>
							
							<span>	
									<input id="prop_choices_other_checkbox" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_choices_other_checkbox">اجازه به کاربر برای اضافه کردن دیگر انتخاب ها</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="فعال کردن این تنظیم اگر شما میخواهید اجازه بدهید به کاربرتان به نوشتن پاسخ خود اگر هیچ یک از گزینه ها قابل اجرا باشد . یک فیلد نوشتنی اضافه خواهد شد در انتهای انتخاب . وارد کنید یک برچسب در زیر این گزینه"/>
									<div style="margin-bottom: 5px;margin-top: 3px;padding-left: 20px">
										<img src="images/icons/tag_green.png" style="vertical-align: middle"> <input id="prop_other_choices_label" style="width: 220px" class="text" value="" size="25" type="text">
									</div>
									<span id="prop_choices_randomize_span" style="display: none">
									<input id="prop_choices_randomize" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_choices_randomize">انتخاب تصادفی</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="فعال کردن این گزینه اگر شما میخواهید گزینه ها بهم ریخته شوند در هر بار که فرم نمایش داده میشود"/>
									</span>
							</span>
							
							</fieldset>
							
						</li>
						
						<li class="clear" id="prop_matrix_row">
							<fieldset class="choices">
							<legend>
							ردیف ها 
							<img class="helpmsg" src="images/icons/help3.png" style="vertical-align: top; " title="بر چسب ردیف را در اینجا وارد کنید . استفاده از دکمه های مثبت منفی برای اضافه کردن سطر ماتریس میباشد"/>
							</legend>
							<ul id="element_matrix_row">
							<li>
								<input type="text" value="سوال اول : " autocomplete="off" class="text" id="matrixrow_1" /> 
								<img title="اضافه کردن" alt="اضافه کردن" src="images/icons/add.png" style="vertical-align: middle" > 
								<img title="حذف" alt="حذف" src="images/icons/delete.png" style="vertical-align: middle" > 
							</li>	
							</ul>
							
							<div style="text-align: center;padding-top: 5px;padding-bottom: 10px">
								<img src="images/icons/page_go.png" style="vertical-align: top"/> <a href="#" id="bulk_import_matrix_row">قرار دادن حجم ردیف ها</a>
							</div> 
							
							</fieldset>
							
						</li>
						<li class="clear" id="prop_matrix_column">
							<fieldset class="choices">
							<legend>
ستون							<img class="helpmsg" src="images/icons/help3.png" style="vertical-align: top; " title="بر چسب ستون را در اینجا وارد کنید . استفاده از دکمه های مثبت منفی برای اضافه کردن ستون ماتریس میباشد  "/>
							</legend>
							<ul id="element_matrix_column">
							<li>
								<input type="text" value="سوال اول : " autocomplete="off" class="text" id="matrixcolumn_1" /> 
								<img title="اضافه کردن" alt="اضافه کردن" src="images/icons/add.png" style="vertical-align: middle" > 
								<img title="حذف" alt="حذف" src="images/icons/delete.png" style="vertical-align: middle" > 
							</li>	
							</ul>
							
							<div style="text-align: center;padding-top: 5px;padding-bottom: 10px">
								<img src="images/icons/page_go.png" style="vertical-align: top"/> <a href="#" id="bulk_import_matrix_column">قرار دادن حجم ستون ها</a>
							</div> 
							
							</fieldset>
							
						</li>
						<li id="prop_breaker"></li> 
						<li class="leftCol" id="prop_options">
							<fieldset class="fieldset">
							<legend>قوانین</legend>
							<input id="element_required" class="checkbox" value="" type="checkbox">
							<label class="choice" for="element_required">ضروری</label>
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="برای اینکه مطمئن شوید که یک کاربر پر میکند یک فیلد خاص رو ، قانونی قرار دهید که نمایش داده شود به کاربر اگر کاربر پر نکرد آن فیلد خاص رو"/>
							<br>
							<span id="element_unique_span">
							<input id="element_unique" class="checkbox" value="" type="checkbox"> 
							<label class="choice" for="element_unique">بدون تکراری</label>
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="بررسی این قانون تایید میکند که اطلاعات وارد شده در این فیلد منحصر به فرد بوده و قبلا ارائه نشده است"/>  </span><br>
							</fieldset>
						</li>
						
						<li class="rightCol" id="prop_access_control">
							<fieldset class="fieldset">
							<legend>فیلد قابل رویت باشد برای</legend>
							<input id="element_public" name="element_visibility" class="radio" value="" checked="checked" type="radio">
							<label class="choice" for="element_public">هر کسی</label>
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="این تنظیم پیش فرض میباشد . این فیلد توسط هر کسی قایل دسترس خواهد بود هنگامی که فرم ساخته شود برای عموم"/>  <br>
							<span id="admin_only_span">
							<input id="element_private" name="element_visibility" class="radio" value="" type="radio">
							<label class="choice" for="element_private">فقط مدیر</label> 
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="وقتی که فیلد روی این گزینه قرار گیرد دیگر برای کاربران عمومی قابل نمایش نخواهد بود"/></span><br>
							</fieldset>
						</li>
						
						<li class="clear" id="prop_time_options">
							<fieldset class="choices">
							<legend>
							تنظیمات زمان 
							</legend>
							
							<span>	
									<input id="prop_time_showsecond" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_time_showsecond">فیلد نمایش ثانیه ها</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="بررسی اینکه فعال باشد فیلد ثانیه ها  در فیلد زمانتان"/>
									<br/>
									<input id="prop_time_24hour" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_time_24hour">استفاده از نوع 24 ساعته</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="اگر این نماد فعال باشد در فرم hh:mm (برای مثال 14:23) یا hh:mm:ss (برای مثال, 14:23:45)"/>
							</span>
							
							</fieldset>
							
						</li>
						
						<li class="clear" id="prop_text_options">
							<fieldset class="choices">
							<legend>
							تنظیمات متن 
							</legend>
							
							<span>	
									<input id="prop_text_as_password" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_text_as_password">نمایش فیلد رمز عبور</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="چک کردن اینکه پسورد داخل فیلد نمایش داده بشود به صورت کاراکترهای مخفی(نشان داده شود به صورت ستاره یا دایره) <br/><br/> آگاهی از اینکه وجود <u>ندارد رمزنگاری</u> برای فیلد پسورد ساخته شده . قادر خواهید بود ببینید از پنل مدیریت/ایمیل به صورت یک متن ساده"/>
							</span>
							
							</fieldset>
							
						</li>
						
						<li class="clear" id="prop_matrix_options">
							<fieldset class="choices">
							<legend>
							تنظیمات ماتریس 
							</legend>
							
							<span>	
									<input id="prop_matrix_allow_multiselect" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_matrix_allow_multiselect">اجازه پاسخ های متعدد در هر سطر</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="بررسی این تنظیم میتواند اجازه بدهد به کاربر شما برای انتخاب چندین جواب برای هر سطر. این گزینه میتواند تنظیم بشود فقط برای یکبار ، در همان زمانی که شما اضافه میکنید فیلد ماتریس را"/>
							</span>
							
							</fieldset>
							
						</li>
						
						<li class="clear" id="prop_address_options">
							<fieldset class="choices">
							<legend>
							تنظیمات آدرس 
							</legend>
							
							<span>	
									<input id="prop_address_hideline2" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_address_hideline2">پنهان کردن آدرس خط 2</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="پنهان میکند ادرس خط 2 را از فیلد آدرس"/>
									<br/>
									<input id="prop_address_us_only" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_address_us_only">محدود به انتخابهای جمهوری اسلامی ایران</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="بررسی این گزینه محدود میکند انتخابها را فقط به جمهوری اسلامی ایران"/>
							</span>
							
							</fieldset>
							
						</li>
						
						<li class="clear" id="prop_date_options">
							<fieldset class="choices">
							<legend>
							تنظیمات تاریخ 
							</legend>
							
							<span>	
									<input id="prop_date_range" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_date_range">فعال کردن حداقل و یا حداکثر تاریخها</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="شما میتوانید قرار دهید حداقل و یا حداکثر تاریخ ها رو برای وقتی که تاریخ در یک بازه زمانی مشخص باشد"/>
									
									<div id="prop_date_range_details" style="display: none;">
										
										<div id="form_date_range_minimum" style=" margin-right: 0; ">
											<label class="desc">حداقل تاریخ :</label>  
											
											
											<span style="display: none;">
											<input type="text" value="" maxlength="2" size="2" style="width: 2em;" class="text" name="date_range_min_mm" id="date_range_min_mm">
											<label for="date_range_min_mm">ماه</label>
											</span>
											
											<span style="display: none;">
											<input type="text" value="" maxlength="2" size="2" style="width: 2em;" class="text" name="date_range_min_dd" id="date_range_min_dd">
											<label for="date_range_min_dd">روز</label>
											</span>
											
											<span style="display: none;">
											 <input type="text" value="" maxlength="4" size="4" style="width: 3em;" class="text" name="date_range_min_yyyy" id="date_range_min_yyyy">
											 <label for="date_range_min_yyyy">سال</label>
											</span>
											
											<input type="text" value=""  id="date_range_min_shamsi" readonly >
											<span style="height: 30px;padding-right: 10px;float:left">
												<input type="hidden" value="" maxlength="4" size="4" style="width: 3em;" class="text" name="linked_picker_range_min" id="linked_picker_range_min">
												<img id="date_range_min_shamsi_pick_img" alt="انتخاب تاریخ." src="images/icons/calendar.png" class="trigger" style="margin-top: 3px; cursor: pointer;" />
											</span>
											
										</div>
											<br><br><br>
										<div id="form_date_range_maximum">
											<label class="desc">حداکثر تاریخ :</label> 
											
											<span style="display: none;">
											<input type="text" value="" maxlength="2" size="2" style="width: 2em;" class="text" name="date_range_max_mm" id="date_range_max_mm">
											<label for="date_range_max_mm">ماه</label>
											</span>
											
											<span style="display: none;">
											<input type="text" value="" maxlength="2" size="2" style="width: 2em;" class="text" name="date_range_max_dd" id="date_range_max_dd">
											<label for="date_range_max_dd">روز</label>
											</span>
											
											<span style="display: none;">
											 <input type="text" value="" maxlength="4" size="4" style="width: 3em;" class="text" name="date_range_max_yyyy" id="date_range_max_yyyy">
											<label for="date_range_max_yyyy">سال</label>
											</span>
											
											<input type="text" value=""  id="date_range_max_shamsi" readonly >
											<span style="height: 30px;padding-right: 10px;float:left">
											<input type="hidden" value="" maxlength="4" size="4" style="width: 3em;" class="text" name="linked_picker_range_max" id="linked_picker_range_max">
											<img id="date_range_max_shamsi_pick_img" alt="تاریخ را انتخاب کنید." src="images/icons/calendar.png" class="trigger" style="margin-top: 3px; cursor: pointer" />
											</span>
											
		 								</div>
											
											<div style="clear: both"></div>
										
									</div>
									
									<div style="clear: both"></div>
									
									<input id="prop_date_selection_limit" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_date_selection_limit">فعال کردن انتخاب تاریخ محدود شده</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="این مفید است برای رزرو و یا فرم رزرو . همچنین شما میتوانید اختصاص بدهید هر روزی را برای بیشترین تعداد از مشتریان . به عنوان مثال : تعیین مقدار به 5 به این معنی است که تضمین میکند که در همان روز نمیگذارد بیش از 5 مشتری باشد"/>
									<div id="form_date_selection_limit" style="display: none">
											تنها اجازه می دهد هر روز انتخاب شود
											<input id="date_selection_max" style="width: 20px" class="text" value="" maxlength="255" type="text"> زمان
									</div>
									<div style="clear: both"></div>
									
									<input id="prop_date_past_future_selection" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_date_past_future_selection">غیر فعال کردن</label>
										<select class="select medium" id="prop_date_past_future" name="prop_date_past_future" autocomplete="off" disabled="disabled">
											<option id="element_date_past" value="p">همه ی تاریخهای گذشته</option>
											<option id="element_date_future" value="f">همه ی تاریخهای آینده</option>
										</select>
									
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="علامت زدن این گزینه انتخاب گذشته و یا انتخاب تاریخ آینده را غیر فعال میکند."/>
									<div style="clear: both"></div>
									
									<input id="prop_date_disable_weekend" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_date_disable_weekend">غیر فعال کردن روزهای آخر هفته</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="بررسی این تنظیم غیر فعال میکند انتخاب های روزهای آخر هفته را"/>
									<div style="clear: both"></div>
									
									<input id="prop_date_disable_specific" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_date_disable_specific">غیر فعال کردن تاریخهای خاص</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="شما میتوانید غیر فعال کنید روزهای خاصی را برای اینکه جلوگیری کنید از انتخاب مشتریانتان در آن روزها . استفاده از جدول تاریخ برای غیر فعال کردن تاریخ های متعدد."/>
									<div id="form_date_disable_specific" style="display: none">
											<textarea class="textarea" rows="10" cols="100" style="width: 175px;height: 45px" id="date_disabled_list"></textarea>
											<div style="display: none"><img id="date_disable_specific_pick_img" alt="جدول تاریخ." src="images/icons/calendar.png" class="trigger" style="vertical-align: top; cursor: pointer" /></div>
									</div>
							</span>
							
							</fieldset>
							
						</li>

						<li class="clear" id="prop_section_options">
							<fieldset class="choices">
							<legend>
							تنظیمات برش مقطعی
							</legend>
							
							<span>	
									<input id="prop_section_email_display" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_section_email_display">نمایش دادن برش مقطعی در ایمیل</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="فعال کردن این گزینه اگر شما نیاز دارید به نمایش دادن محتوا با برش مقطعی در ایمیل و صفحه بررسی و صفحه مطالب"/>
									
									<div style="clear: both"></div>
									
									<input id="prop_section_enable_scroll" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_section_enable_scroll">فعال کردن نوار اسکرول</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="بخش مورد نظر تنظیم خواهد شد با یک ارتفاع ثابت و نمایش یک اسکرول عمودی برای نیاز در بالا و پایین بردن . این بسیار مفید است برای متن های بسیار طولانی از جمله شرایطو ضوابط و یا موافقت نامه ها در قرار دادها"/>
									<div id="div_section_size" style="display: none">
											سایز برش مقطعی :
											<select class="select" id="prop_section_size" autocomplete="off" tabindex="13" style="width: 100px">
												<option value="small">کوچک</option>
												<option value="medium">متوسط</option>
												<option value="large">بزرگ</option>
											</select>
									</div>
									<div style="clear: both"></div>
							</span>
							
							</fieldset>
							
						</li>
						
						<li class="clear" id="prop_file_options">
							<fieldset class="choices">
							<legend>
							تنظیمات آپلود 
							</legend>
							
							<span>
							
									<input id="prop_file_enable_type_limit" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_file_enable_type_limit">محدود کردن نوع فایل آپلودی</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="شما میتوانید جلوگیری کنید یااجازه دهید تا فقط نوع های خاصی از فایلها آپلود شوند .نوع پسوند فایل را وارد کنید و سپس با کاما جدا کنید (برای مثال : jpg,bmp,zip )"/>
									<div id="form_file_limit_type" style="display: none">
											<select class="select" id="prop_file_block_or_allow" name="prop_file_block_allow" autocomplete="off" style="width: 90px">
											<option id="element_file_allow" value="a">تنها اجازه می دهد</option>
											<option id="element_file_block" value="b">محدود کردن</option>
											</select> <label class="choice" for="file_type_list">لیست کنید نوع فایل ها را در زیر :</label>
											<textarea class="textarea" rows="10" cols="100" style="width: 230px; height: 30px;margin-top: 5px" id="file_type_list"></textarea>
									</div>
									<div style="clear: both"></div>									
									<input id="prop_file_as_attachment" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_file_as_attachment">ارسال فایل به عنوان پیوست ایمیل</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="بطور پیشفرض تمامی فایل های آپلود شده ارسال خواهند شد به ایمیل شما با یک لینک دانلود . غیر فعال کردن این گزینه فایل ها را ارسال میکند به ایمیل ضمیمه شده . هشدار : غیر فعال کنید این گزینه را اگر انتظار دارید که مشتریانتان فایلهای با حجم زیاد ارسال میکنند . اگر فایلهای ارسال شده بیشتر از میزان فضای سرور شما باشند به ایمیل ارسال نخواهد شد."/>
									
									<div style="clear: both"></div>
									
									<input id="prop_file_enable_advance" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_file_enable_advance">فعال کردن آپلود کننده پیشرفته</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="فعال کردن این گزینه قابلیتهای پیشرفته را فعال میسازد ، مانند نوار پیشرفت بارگزاری ، ارسال فایلهای مختلف ، ارسال آژاکس ، محدود کردن حجم فایل . توصیه میشود که این گزینه فعال باشد"/>
										
							</span>
							
							</fieldset>
							
						</li>
						
						<li class="clear" id="prop_file_advance_options">
							<fieldset class="choices">
							<legend>
							تنظیمات آپلود کننده پیشرفته 
							</legend>
							
							<span>
									<input id="prop_file_auto_upload" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_file_auto_upload">بارگزاری فایل به صورت خودکار</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="بصورت پیش فرض کلید آپلود یا کلید ارسال فرم نیاز دارد به اینکه قبل از این دو روی دکمه شروع اپلود فایلها کلیک کرد"/>
									<div style="clear: both"></div>	
									
									<input id="prop_file_multi_upload" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_file_multi_upload">اجازه دادن آپلود چندین فایل</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="علامت زدن این گزینه اجازه میدهد که چند فایل آپلود شود . شما همچنین می توانید تعداد فایل برای آپلود را محدود کنید"/>
									<div id="form_file_max_selection">
										 تعداد فایل برای آپلود 
											<input id="file_max_selection" style="width: 20px" class="text" value="" maxlength="255" type="text"> فایل ها
									</div>
									<div style="clear: both"></div>
									
									<input id="prop_file_limit_size" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_file_limit_size">محدود کردن حجم فایل</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="شما میتوانید تنظیم کنید که فایل آپلودی حجم آن تا چه اندازه باشد"/>
									<div id="form_file_limit_size">
											محدود کردن هر فایل تا حداکثر 
											<input id="file_size_max" style="width: 20px" class="text" value="" maxlength="255" type="text"> مگابایت
									</div>
									

							</span>
							
							</fieldset>
							
						</li>
						
						<li class="clear" id="prop_range">
							<fieldset class="range">
							<legend>
								محدوده
							</legend>
							
							<div style="padding-left: 2px">
								<span>
								<label for="element_range_min" class="desc">کمترین</label>
								<input type="text" value="" class="text" name="element_range_min" id="element_range_min">
								</span>
								<span>
								<label for="element_range_max" class="desc">بیشترین</label>
								<input type="text" value="" class="text" name="element_range_max" id="element_range_max">
								</span>
								<span>
								<label for="element_range_limit_by" class="desc">محدود شده بوسیله
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="شما میتوانید محدود کنید  مقداری از کاراکترهای تایپ شده بین کاراکترهای خاص یا کلمات یا بین مقدارهای خاص عددی فیلد . ترک مقدار خالی یا 0 اگر شما نمیخواهید قرار دهید هیچ گونه محدودیتی"/></label>
									<select class="select" name="element_range_limit_by" id="element_range_limit_by">
									<option value="c">کاراکترها</option>
									<option value="w">کلمات</option>
								</select>
								<select class="select" name="element_range_number_limit_by" id="element_range_number_limit_by">
									<option value="v">مقدار</option>
									<option value="d">شماره</option>
								</select>
								</span>
								
							</div>
							</fieldset>
						</li>

						<li class="clear" id="prop_number_advance_options">
							<fieldset class="choices">
							<legend>
							تنظیمات پیشرفته 
							</legend>
							
							<span>
									<input id="prop_number_enable_quantity" class="checkbox" value="" type="checkbox">
									<label class="choice" for="prop_number_enable_quantity">فعال کردن مقدار فیلد</label>
									<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="فعال کردن این گزینه اگر فرم شما پرداخت آن فعال میباشد نیاز دارد به مقدار فیلد برای پرداخت کل قیمت . انتخاب فیلد مورد نظر برای انجام محاسبات از لیست کشویی . فیلد مورد نظر باید یکی از نوع های زیر باشد : انتخاب چندگانه ، کشویی ، چک باکس ، قیمت"/>
									<div id="prop_number_quantity_link_div" style="display: none">
											محاسبه با این فیلد: <br />
											<select class="select large" id="prop_number_quantity_link" name="prop_number_quantity_link" style="width: 95%" autocomplete="off">
												<option value=""> -- فیلد مورد نظر پشتیبانی نمیشود -- </option>
											</select>
									</div>
							</span>
							
							</fieldset>
							
						</li>
						
						<li class="clear" id="prop_default_value">
							<label class="desc" for="element_default">
							مقدار پیش فرض
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="با تنظیم این مقدار ، فیلد پر خواهد شد از قبل با متنی که شما وارد کرده اید"/>
							</label>
							
							<input id="element_default_value" class="text large" name="element_default_value" value="" type="text">
						</li>
						
						<li class="clear" id="prop_default_phone">
							<label class="desc" for="element_default_phone"><img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="در صورتی که تمام گزینه ها بر روی ضروری قرار دهید کاربر ملزم است این فیلدها را حتما پر کند و اگر بر روی غیر ضروری قرار دهید کاربر ملزم نیست که فیلدها را حتما پر کند"/>
							ضروری و غیر ضروری کردن تاریخ شمسی
							
							</label>
							
<select size="2" style="width:88px;" id="element_default_phone1" class="text"  name="element_default_phone1"><option value="">ضروری</option><option value="000">غیر ضروری</option></select>
 							<select size="2" style="width:88px;" id="element_default_phone2" class="text"  name="element_default_phone2"><option value="">ضروری</option><option value="000">غیر ضروری</option></select>
							<select size="2" style="width:88px;" id="element_default_phone3" class="text"  name="element_default_phone3"><option value="">ضروری</option><option value="0000">غیر ضروری</option></select>
						</li>
						
						<li class="clear" id="prop_default_date">
							<label class="desc" for="element_default_date">
تاریخ پیش فرض							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="با تنظیم این مقدار ، تاریخ پر خواهد شد با تاریخی که شما وارد میکنید . استفاده از قالب ##/##/#### و یا کلماتی که در تاریخ زبان انگلیسی به کار میرود ، مانند 'امروز'، 'فردا'، 'جمعه گذشته'، '1 هفته'، 'آخرین روز از ماه آینده'، '3 روز قبل '،' دوشنبه هفته آینده'"/>
							</label>
							
							<input id="element_default_date" class="text large" name="element_default_date" value="" type="text">
						</li>
						
						<li class="clear" id="prop_default_value_textarea">
							<label class="desc" for="element_default_textarea">
							مقدار پیش فرض
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="با تنظیم این مقدار ، فیلد پر خواهد شد از قبل با متنی که شما وارد کرده اید."/>
							</label>
							
							<textarea class="textarea" rows="10" cols="50" id="element_default_value_textarea" name="element_default_value_textarea"></textarea>
						</li>
						
						<li class="clear" id="prop_default_country">
							<label class="desc" for="fieldaddress_default">
							کشور پیش فرض
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="با تنظیم این مقدار ، کشور داخل فیل پر میشود با انتخابی که شما میکنید"/>
							</label>
							<select class="select" id="element_countries" name="element_countries">
							<option value=""></option>
							
							<optgroup label="North America">
							<option value="Antigua and Barbuda">Antigua and Barbuda</option>
							<option value="Bahamas">Bahamas</option>
							<option value="Barbados">Barbados</option> 
							<option value="Belize">Belize</option> 
							<option value="Canada">Canada</option> 
							<option value="Costa Rica">Costa Rica</option> 
							<option value="Cuba">Cuba</option> 
							<option value="Dominica">Dominica</option> 
							<option value="Dominican Republic">Dominican Republic</option>
							<option value="El Salvador">El Salvador</option>
							<option value="Grenada">Grenada</option> 
							<option value="Guatemala">Guatemala</option> 
							<option value="Haiti">Haiti</option> 
							<option value="Honduras">Honduras</option> 
							<option value="Jamaica">Jamaica</option> 
							<option value="Mexico">Mexico</option> 
							<option value="Nicaragua">Nicaragua</option> 
							<option value="Panama">Panama</option> 
							<option value="Puerto Rico">Puerto Rico</option> 
							<option value="Saint Kitts and Nevis">Saint Kitts and Nevis</option> 
							<option value="Saint Lucia">Saint Lucia</option>
							<option value="Saint Vincent and the Grenadines">Saint Vincent and the Grenadines</option> 
							<option value="Trinidad and Tobago">Trinidad and Tobago</option>
							</optgroup>
							
							<optgroup label="South America">
							<option value="Argentina">Argentina</option>
							<option value="Bolivia">Bolivia</option> 
							<option value="Brazil">Brazil</option> 
							<option value="Chile">Chile</option> 
							<option value="Columbia">Columbia</option>
							<option value="Ecuador">Ecuador</option> 
							<option value="Guyana">Guyana</option> 
							<option value="Paraguay">Paraguay</option> 
							<option value="Peru">Peru</option> 
							<option value="Suriname">Suriname</option> 
							<option value="Uruguay">Uruguay</option> 
							<option value="Venezuela">Venezuela</option>
							</optgroup>
							
							<optgroup label="Europe">
							<option value="Albania">Albania</option>
							<option value="Andorra">Andorra</option>
							<option value="Armenia">Armenia</option>
							<option value="Austria">Austria</option>
							<option value="Azerbaijan">Azerbaijan</option>
							<option value="Belarus">Belarus</option>
							<option value="Belgium">Belgium</option> 
							<option value="Bosnia and Herzegovina">Bosnia and Herzegovina</option>
							<option value="Bulgaria">Bulgaria</option> 
							<option value="Croatia">Croatia</option> 
							<option value="Cyprus">Cyprus</option> 
							<option value="Czech Republic">Czech Republic</option>
							<option value="Denmark">Denmark</option> 
							<option value="Estonia">Estonia</option> 
							<option value="Finland">Finland</option> 
							<option value="France">France</option> 
							<option value="Georgia">Georgia</option>
							<option value="Germany">Germany</option>
							<option value="Greece">Greece</option>
							<option value="Guernsey">Guernsey</option>
							<option value="Hungary">Hungary</option> 
							<option value="Iceland">Iceland</option> 
							<option value="Ireland">Ireland</option> 
							<option value="Italy">Italy</option> 
							<option value="Latvia">Latvia</option> 
							<option value="Liechtenstein">Liechtenstein</option>
							<option value="Lithuania">Lithuania</option> 
							<option value="Luxembourg">Luxembourg</option> 
							<option value="Macedonia">Macedonia</option> 
							<option value="Malta">Malta</option> 
							<option value="Moldova">Moldova</option> 
							<option value="Monaco">Monaco</option> 
							<option value="Montenegro">Montenegro</option> 
							<option value="Netherlands">Netherlands</option> 
							<option value="Norway">Norway</option> 
							<option value="Poland">Poland</option> 
							<option value="Portugal">Portugal</option>
							<option value="Romania">Romania</option> 
							<option value="San Marino">San Marino</option>
							<option value="Serbia">Serbia</option>
							<option value="Slovakia">Slovakia</option>
							<option value="Slovenia">Slovenia</option> 
							<option value="Spain">Spain</option> 
							<option value="Sweden">Sweden</option> 
							<option value="Switzerland">Switzerland</option> 
							<option value="Ukraine">Ukraine</option> 
							<option value="United Kingdom">United Kingdom</option>
							<option value="Vatican City">Vatican City</option>
							</optgroup>
							
							<optgroup label="Asia">
							<option value="Afghanistan">Afghanistan</option>
							<option value="Bahrain">Bahrain</option>
							<option value="Bangladesh">Bangladesh</option>
							<option value="Bhutan">Bhutan</option>
							<option value="Brunei Darussalam">Brunei Darussalam</option>
							<option value="Myanmar">Myanmar</option>
							<option value="Cambodia">Cambodia</option>
							<option value="China">China</option>
							<option value="East Timor">East Timor</option>
							<option value="Hong Kong">Hong Kong</option> 
							<option value="India">India</option>
							<option value="Indonesia">Indonesia</option>
                            <option value="United States">ایران</option>
							<option value="Iraq">Iraq</option>
							<option value="Israel">Israel</option>
							<option value="Japan">Japan</option>
							<option value="Jordan">Jordan</option>
							<option value="Kazakhstan">Kazakhstan</option>
							<option value="North Korea">North Korea</option>
							<option value="South Korea">South Korea</option>
							<option value="Kuwait">Kuwait</option> 
							<option value="Kyrgyzstan">Kyrgyzstan</option> 
							<option value="Laos">Laos</option> 
							<option value="Lebanon">Lebanon</option> 
							<option value="Malaysia">Malaysia</option> 
							<option value="Maldives">Maldives</option> 
							<option value="Mongolia">Mongolia</option> 
							<option value="Nepal">Nepal</option> 
							<option value="Oman">Oman</option> 
							<option value="Pakistan">Pakistan</option> 
							<option value="Philippines">Philippines</option> 
							<option value="Qatar">Qatar</option> 
							<option value="Russia">Russia</option> 
							<option value="Saudi Arabia">Saudi Arabia</option> 
							<option value="Singapore">Singapore</option> 
							<option value="Sri Lanka">Sri Lanka</option>
							<option value="Syria">Syria</option>
							<option value="Taiwan">Taiwan</option> 
							<option value="Tajikistan">Tajikistan</option> 
							<option value="Thailand">Thailand</option> 
							<option value="Turkey">Turkey</option> 
							<option value="Turkmenistan">Turkmenistan</option> 
							<option value="United Arab Emirates">United Arab Emirates</option>
							<option value="Uzbekistan">Uzbekistan</option> 
							<option value="Vietnam">Vietnam</option> 
							<option value="Yemen">Yemen</option>
							</optgroup>
							
							<optgroup label="Oceania">
							<option value="Australia">Australia</option>
							<option value="Fiji">Fiji</option> 
							<option value="Kiribati">Kiribati</option>
							<option value="Marshall Islands">Marshall Islands</option> 
							<option value="Micronesia">Micronesia</option> 
							<option value="Nauru">Nauru</option> 
							<option value="New Zealand">New Zealand</option>
							<option value="Palau">Palau</option>
							<option value="Papua New Guinea">Papua New Guinea</option>
							<option value="Samoa">Samoa</option> 
							<option value="Solomon Islands">Solomon Islands</option>
							<option value="Tonga">Tonga</option> 
							<option value="Tuvalu">Tuvalu</option>  
							<option value="Vanuatu">Vanuatu</option>
							</optgroup>
							
							<optgroup label="Africa">
							<option value="Algeria">Algeria</option> 
							<option value="Angola">Angola</option> 
							<option value="Benin">Benin</option> 
							<option value="Botswana">Botswana</option> 
							<option value="Burkina Faso">Burkina Faso</option> 
							<option value="Burundi">Burundi</option> 
							<option value="Cameroon">Cameroon</option> 
							<option value="Cape Verde">Cape Verde</option>
							<option value="Central African Republic">Central African Republic</option>
							<option value="Chad">Chad</option>  
							<option value="Comoros">Comoros</option>  
							<option value="Congo">Congo</option>
							<option value="Djibouti">Djibouti</option> 
							<option value="Egypt">Egypt</option> 
							<option value="Equatorial Guinea">Equatorial Guinea</option> 
							<option value="Eritrea">Eritrea</option> 
							<option value="Ethiopia">Ethiopia</option> 
							<option value="Gabon">Gabon</option> 
							<option value="Gambia">Gambia</option> 
							<option value="Ghana">Ghana</option> 
							<option value="Guinea">Guinea</option> 
							<option value="Guinea-Bissau">Guinea-Bissau</option>
							<option value="Côte d'Ivoire">Côte d'Ivoire</option> 
							<option value="Kenya">Kenya</option> 
							<option value="Lesotho">Lesotho</option> 
							<option value="Liberia">Liberia</option> 
							<option value="Libya">Libya</option> 
							<option value="Madagascar">Madagascar</option> 
							<option value="Malawi">Malawi</option> 
							<option value="Mali">Mali</option>
							<option value="Mauritania">Mauritania</option> 
							<option value="Mauritius">Mauritius</option> 
							<option value="Morocco">Morocco</option> 
							<option value="Mozambique">Mozambique</option> 
							<option value="Namibia">Namibia</option>
							<option value="Niger">Niger</option>
							<option value="Nigeria">Nigeria</option> 
							<option value="Rwanda">Rwanda</option> 
							<option value="Sao Tome and Principe">Sao Tome and Principe</option>
							<option value="Senegal">Senegal</option> 
							<option value="Seychelles">Seychelles</option> 
							<option value="Sierra Leone">Sierra Leone</option>
							<option value="Somalia">Somalia</option> 
							<option value="South Africa">South Africa</option>
							<option value="Sudan">Sudan</option> 
							<option value="Swaziland">Swaziland</option> 
							<option value="United Republic of Tanzania">Tanzania</option>
							<option value="Togo">Togo</option> 
							<option value="Tunisia">Tunisia</option> 
							<option value="Uganda">Uganda</option> 
							<option value="Zambia">Zambia</option> 
							<option value="Zimbabwe">Zimbabwe</option>
							</optgroup>
							</select>
						</li>
						
						<li class="clear" id="prop_phone_default">
							<label class="desc" for="element_phone_default1">
							مقدار پیش فرض
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="با تنظیم این مقدار ، فیلد پر خواهد شد از قبل با متنی که شما وارد کرده اید"/>
							</label>
							
							( <input id="element_phone_default1" class="text" size="3" name="text" value="" tabindex="11" maxlength="3" onkeyup="set_properties(JJ('#element_phone_default1').val().toString()+JJ('#element_phone_default2').val().toString()+JJ('#element_phone_default3').val().toString(), 'default_value')" onblur="set_properties(JJ('#element_phone_default1').val().toString()+JJ('#element_phone_default2').val().toString()+JJ('#element_phone_default3').val().toString(), 'default_value')" type="text"> ) 
							
							<input id="element_phone_default2" class="text" size="3" name="text" value="" tabindex="11" maxlength="3" onkeyup="set_properties(JJ('#element_phone_default1').val().toString()+JJ('#element_phone_default2').val().toString()+JJ('#element_phone_default3').val().toString(), 'default_value')" onblur="set_properties(JJ('#element_phone_default1').val().toString()+JJ('#element_phone_default2').val().toString()+JJ('#element_phone_default3').val().toString(), 'default_value')" type="text"> -
							<input id="element_phone_default3" class="text" size="4" name="text" value="" tabindex="11" maxlength="4" onkeyup="set_properties(JJ('#element_phone_default1').val().toString()+JJ('#element_phone_default2').val().toString()+JJ('#element_phone_default3').val().toString(), 'default_value')" onblur="set_properties(JJ('#element_phone_default1').val().toString()+JJ('#element_phone_default2').val().toString()+JJ('#element_phone_default3').val().toString(), 'default_value')" type="text">
						</li>
						
						
						<li class="clear" id="prop_instructions">
							<label class="desc" for="element_instructions">
							راهنمایی برای کاربر 
							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="این متن برای کاربران شما نمایش داده میشود وقتی که آنها هستند در حال پر کردن فیلد"/>
							</label>
							
							<textarea class="textarea" rows="10" cols="50" id="element_instructions"></textarea>
						</li>
						
						<li class="clear" id="prop_custom_css">
							<label class="desc" for="element_custom_css">
شیوه نامه های اختیاری							<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="این است تنظیمات پیشرفته . شما میتوانید اضافه کنید شیوه نامه های اختیاری به عناصر اصلی از فیلد . این است بسیار مفید اگر شما میخواهید سفارشی کنید ظاهر طراحی شده ی فیلدهایتان میتوانید از کدهای سی اس اس خود استفاده نمایید. این شیوه نامه های اختیاری نمیتوانند بمانند در فرم ساز ، فقط روی فرمی که ایجاد کرده اید قابل اجرا هستند"/>
							</label>
							
							<input id="element_custom_css" class="text large" name="element_custom_css" value="" maxlength="255" type="text">
						</li>
						
						<li class="clear" id="prop_page_break_button" style="margin-top: 50px;margin-bottom: 50px">
								<fieldset style="padding-top: 15px">
								<legend>صفحه ثبت کردن دکمه ها</legend>
								
								<div class="left" style="padding-bottom: 5px">
								<input id="prop_submit_use_text" name="submit_use_image" class="radio" value="0" type="radio">
								<label class="choice" for="prop_submit_use_text">استفاده از دکمه متنی</label>
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="این گزینه به صورت پیش فرض توصیه میشود . در همه کلیدها از متن ساده و روان استفاده کنید . شما میتوانید تغییر دهید متنی که درون هر صفحه استفاده شده ، ارسال/بازگشت را فشار دهید."/>
								</div>
								
								<div class="left" style="padding-left: 5px;padding-bottom: 5px">
								<input id="prop_submit_use_image" name="submit_use_image" class="radio" value="1" type="radio">
								<label class="choice" for="prop_submit_use_image">استفاده از دکمه تصویری</label>
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="انتخاب این گزینه اگر شما ترجیح می دهید به استفاده از دکمه های ارسال/بازگشت تصویری خود ، به تصویر خودتان حتما یک آدرس 'یو آر ال' کامل بدهید"/>
								</div>
								
								<div id="div_submit_use_text" class="left" style="padding-left: 8px;padding-bottom: 10px;width: 92%">
								<label class="desc" for="submit_primary_text">دکمه ارسال</label>
								<input id="submit_primary_text" class="text large" name="submit_primary_text" value="" type="text">
								<label id="lbl_submit_secondary_text" class="desc" for="submit_secondary_text" style="margin-top: 10px">دکمه بازگشت</label>
								<input id="submit_secondary_text" class="text large" name="submit_secondary_text" value="" type="text">
								</div>
								
								<div id="div_submit_use_image" class="left" style="padding-left: 8px;padding-bottom: 10px;width: 92%; display: none">
								<label class="desc" for="submit_primary_img">دکمه ارسال. آدرس تصویر:</label>
								<input id="submit_primary_img" class="text large" name="submit_primary_img" value="http://" type="text">
								<label id="lbl_submit_secondary_img" class="desc" for="submit_secondary_img" style="margin-top: 10px">دکمه ارسال. آدرس تصویر:</label>
								<input id="submit_secondary_img" class="text large" name="submit_secondary_img" value="http://" type="text">
								</div>
								</fieldset>
						</li>
						
						</ul>
						<div class="bullet_bar_bottom">
							<img style="float: left" src="images/bullet_green.png" />
							<img style="float: right" src="images/bullet_green.png"/>
						</div>
					</div>
					
				</form>
			</div> <!-- end field properties pane -->
		</div>
		
		<div id="tab_form_properties" style="display: none">
			<div id="form_properties_pane" class="box">
				<div id="form_properties_holder">
						<div class="bullet_bar_top">
							<img style="float: left" src="images/bullet_pink.png" />
							<img style="float: right" src="images/bullet_pink.png"/>
						</div>

						<!--  start form properties pane -->
						<form id="form_properties" action="" onsubmit="return false;">
							<ul id="all_form_properties">
							<li class="form_prop">
								<label class="desc" for="form_title">عنوان فرم 
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="عنوان فرم شما نمایش داده میشود به کاربر موقعی که کاربر در حال مشاهده فرم شما میباشد"/>
								</label>
								<input id="form_title" name="form_title" class="text large" value="" tabindex="1"  type="text">
							</li>
							<li class="form_prop">
								<label class="desc" for="form_description">توضیح 
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="این به طور مستقیم در زیر عنوان فرم نمایش داده میشود و مفید میباشد برای نمایش توضیحات کوتاه و یا هر دستورالعمل ها، یادداشت ها."/>
								</label>
								<textarea class="textarea small" rows="10" cols="50" id="form_description" tabindex="2"></textarea>
							</li>
							
							<li id="form_prop_confirmation" class="form_prop">
								<fieldset>
								<legend>تاییدیه ثبت نام</legend>
								
								<div class="left" style="padding-bottom: 5px">
								<input id="form_success_message_option" name="confirmation" class="radio" value="" checked="checked" type="radio">
								<label class="choice" for="form_success_message_option">نمایش متن</label>
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="این پیغام نمایش داده میشود به کاربر هنگامی که با موفقیت ارسال میکند اطلاعاتش را"/>
								</div>
								
								<div class="left" style="padding-left: 15px;padding-bottom: 5px">
								<input id="form_redirect_option" name="confirmation" class="radio" value="" type="radio">
								<label class="choice" for="form_redirect_option">انتقال به وبسایت</label>
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="بعد از اینکه کاربران اطلاعات خودشآن را با موفقیت ارسال کردند میتوانید شما انتقال بدهید آنها را به وبسایتی با اختیار خودتان"/>
								</div>
								
								<textarea class="textarea" rows="10" cols="50" id="form_success_message" tabindex="9"></textarea>
								
								<input id="form_redirect_url" class="text hide" name="form_redirect_url" value="http://" type="text">
								</fieldset>
							</li>
							
							<li id="form_prop_toggle" class="form_prop">
							<div style="text-align: right">
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="تمام تنظیمات زیر این نقطه انتخابی هستند. شما میتوانید رها کنید آن را اگر به آن نیازی ندارید."/> <a href=""  id="form_prop_toggle_a">نمایش تنظیمات بیشتر</a> <img style="vertical-align: top;cursor: pointer" src="images/icons/resultset_next.gif" id="form_prop_toggle_img"/>
							</div> 
							</li>
							
							<li id="form_prop_language" class="leftCol advanced_prop form_prop">
								<label class="desc">
								زبان ها 
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="شما می توانید زبان های مورد استفاده برای نمایش پیام های فرم خود را انتخاب کنید."/>
								</label>
								<div>
								<select autocomplete="off" id="form_language" class="select large">
								<option value="bulgarian">Bulgarian</option>
								<option value="chinese">Chinese (Traditional)</option>
								<option value="chinese_simplified">Chinese (Simplified)</option>
								<option value="danish">Danish</option>
								<option value="dutch">Dutch</option>
								<option value="english">فارسی</option>
								<option value="estonian">Estonian</option>
								<option value="finnish">Finnish</option>
								<option value="french">French</option>
								<option value="german">German</option>
								<option value="greek">Greek</option>
								<option value="hungarian">Hungarian</option>
								<option value="indonesia">Indonesia</option>
								<option value="italian">Italian</option>
								<option value="japanese">Japanese</option>
								<option value="norwegian">Norwegian</option>
								<option value="polish">Polish</option>
								<option value="portuguese">Portuguese</option>
								<option value="romanian">Romanian</option>
								<option value="russian">Russian</option>
								<option value="slovak">Slovak</option>
								<option value="spanish">Spanish</option>
								<option value="swedish">Swedish</option>
								</select>
								</div>
							</li>
							
							<li id="form_prop_label_alignment" class="rightCol advanced_prop form_prop">
								<label for="form_label_alignment" class="desc">تراز برچسب 
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="تنظیم درست قرار دادن برچسب ها"/>
								</label>
								<div>
								<select autocomplete="off" id="form_label_alignment" class="select large">
								<option value="top_label">مکان بالا</option>
								<option value="left_label">مکان چپ</option>
								<option value="right_label">مکان راست</option>
								</select>
								</div>
							</li>
							
							
								
						
							
							<li id="form_prop_processing" class="clear advanced_prop form_prop">
								<fieldset>
								<legend>تنظیمات پردازش</legend>
									<span>
										<input id="form_resume" class="checkbox" value="" type="checkbox"> 
										<label class="choice" for="form_resume">اجازه میدهد به مشتریان به ذخیره و ادامه بعد از</label>
										<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="نمایش لینک اضافی در پایین فرم شما که اجازه میدهد به مشتریانتان که دخیره کنند اطلاعاتشان رو و بعد ادامه دهند . این تنظیم موقعی کار میکند که شما دارید فرمی که دارای دو صفحه میباشد (داشته باشید یک یا چند صفحه جداگانه)"/>
									</span><br>
									<span>	
										<input id="form_review" class="checkbox" value="" type="checkbox">
										<label class="choice" for="form_review">نمایش دوباره صفحه قبل از ارسال</label>
										<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="اگر این فعال باشد ، شما به مشتریان خود این امکان را میدهید که قبل از ارسال فرم ، با زدن دکمه ادامه اطلاعات فرم خود را ببینند و چک کنند تا در صورت وجود مشکل بتوانند برگشت کرده و آنها را اصلاح کنند"/>
									</span><br>
								</fieldset>
							</li>
							
							<li class="clear advanced_prop form_prop" id="form_prop_review" style="display: none;zoom: 1">
								<fieldset style="padding-top: 15px">
								<legend>تنظیمات صفحه ی چک کننده اطلاعات</legend>
								
								<label class="desc" for="form_review_title">
								عنوان صفحه چک کننده اطلاعات
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="این عنوان نمایش داده میشود در صفحه ی چک کننده ی اطلاعات"/>
								</label>
								
								<input id="form_review_title" class="text large" name="form_review_title" value="" maxlength="255" type="text">
								
								<label class="desc" for="form_review_description">
								توضیح صفحه ی چک کننده اطلاعات 
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="نمایش بعضی توضیحات در صفحه ی چک کننده ی اطلاعات"/>
								</label>
								
								<textarea class="textarea" rows="10" cols="50" id="form_review_description" style="height: 2.5em"></textarea>
								<div style="border-bottom: 1px dashed green; height: 15px;margin-right: 10px"></div>
								<div class="left" style="padding-bottom: 5px;margin-top: 12px">
								<input id="form_review_use_text" name="form_review_option" class="radio" value="0" type="radio">
								<label class="choice" for="form_review_use_text">استفاده کردن دکمه متنی</label>
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="این به طور پیش فرض است و گزینه توصیه می شود. استفاده کنید از متن ساده در دکمه های صفحه ی چک کننده اطلاعات"/>
								</div>
								
								<div class="left" style="padding-left: 5px;padding-bottom: 5px;margin-top: 12px">
								<input id="form_review_use_image" name="form_review_option" class="radio" value="1" type="radio">
								<label class="choice" for="form_review_use_image">استفاده از دکمه تصویری</label>
								<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="اگر شما ترجیح میدهید به استفاده از دکمه های تصویری ارسال/برگشت خودتان این گزینه را انتخاب کنید . حتما برای تصویرتان یک آدرس ' یو آر ال ' بدهید"/>
								</div>
								
								<div id="div_review_use_text" class="left" style="padding-left: 8px;padding-bottom: 10px;width: 92%">
								<label class="desc" for="review_primary_text">دکمه ارسال</label>
								<input id="review_primary_text" class="text large" name="review_primary_text" value="" type="text">
								<label id="lbl_review_secondary_text" class="desc" for="review_secondary_text" style="margin-top: 3px">دکمه برگشت</label>
								<input id="review_secondary_text" class="text large" name="review_secondary_text" value="" type="text">
								</div>
								
								<div id="div_review_use_image" class="left" style="padding-left: 8px;padding-bottom: 10px;width: 92%;display: none">
								<label class="desc" for="review_primary_img">دکمه ارسال. آدرس تصویر:</label>
								<input id="review_primary_img" class="text large" name="review_primary_img" value="http://" type="text">
								<label id="lbl_review_secondary_img" class="desc" for="review_secondary_img" style="margin-top: 3px">دکمه برگشت. آدرس تصویر:</label>
								<input id="review_secondary_img" class="text large" name="review_secondary_img" value="http://" type="text">
								</div>
								</fieldset>
							</li>
							 
							<li id="form_prop_protection" class="advanced_prop form_prop">
								<fieldset>
								<legend>حفاظت &amp; محدود کردن</legend>
									<span>	
										<input id="form_password_option" class="checkbox" value=""  type="checkbox">
										<label class="choice" for="form_password_option">روشن کردن حفاظت از رمز عبور</label>
										<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="اگر فعال باشد همه کاربرانتان برای استفاده از فرم های عمومی باید رمز عبوری وارد کنند به این معنی که فرم شما با پسورد محافظت خواهد شد"/>
										<div id="form_password" style="display: none">
											<img src="images/icons/key.png" alt="کلمه عبور : " style="vertical-align: middle">
											<input id="form_password_data" style="width: 50%" class="text" value="" size="25"  type="password">
										</div>
									</span>
									
									<span style="clear: both;display: block">
										<input id="form_captcha" class="checkbox" value="" type="checkbox">
										<label class="choice" for="form_captcha">روشن کردن در حفاظت از هرزنامه (CAPTCHA)</label>
										<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="اگر فعال باشد، یک تصویر را با کلمات تصادفی تولید خواهد شد و کاربران نیاز دارند تا برای ارسال فرمشان آن کلمات را وارد کنند . این برای جلوگیری از سوء استفاده و بمباران و یا برنامه های خودکاری که در این زمینه استفاده میشوند برای تولید اسپم بسیار مفید میباشد"/>
										<div id="form_captcha_type_option" style="display: block">
											
											<label class="choice" for="form_captcha_type">نوشتن: </label>
											<select class="select" id="form_captcha_type" name="form_captcha_type" autocomplete="off">
												<option value="r">reCAPTCHA (سخت ترین)</option>
												<option value="i">Simple Image (نرمال)</option>
												<option value="t">Simple Text (آسان ترین)</option>
											</select>
											<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="شما می توانید سطح دشواری حفاظت از هرزنامه را انتخاب کنید."/>
											 <br/>
											 <br/>
											reCAPTCHA : نمایش یک تصویر با کلمات تحریف شده.صوتی نیز گنجانده شده است. این امن ترین و همچنین سخت ترین برای خواندن است.و ممکن است برای برخی افراد آزار دهنده باشد.
											 <br/>
											 <br/> 
											Simple Image : نمایش یک تصویر با کلمات واضح و روشن . اکثر افراد میتوانند به راحتی آن را بخوانند
											 <br/>
											 <br/>
											Simple Text : نمایش یک متن (و نه یک تصویر) که باید یک سوال ساده را حل کند.
										</div>
									</span>
									<span style="clear: both;display: block">
										<input id="form_unique_ip" class="checkbox" value="" type="checkbox">
										<label class="choice" for="form_unique_ip">محدود کردن توسط آی پی</label>
										<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="با استفاده از این گزینه شما از پر کردن چندین فرم توسط یک کاربر جلوگیری میکنید.این کار فقط با مقایسه آی پی کاربر انجام میشود"/>
									</span>
									<span style="clear: both;display: block">	
										<input id="form_limit_option" class="checkbox" value="" type="checkbox">
										<label class="choice" for="form_limit_option">محدود کردن ارسالی ها</label>
										<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="فرم خاموش خواهد شد پس از رسیدن به تعداد ورودی تعریف شده در اینجا"/>
										<div id="form_limit_div" style="display: none">
											<img src="images/icons/flag_red.png" alt="حداکثر ورودی های مورد قبول:" style="vertical-align: middle"> حداکثر ورودی های مورد قبول:
											<input id="form_limit" style="width: 20%" class="text" value="" maxlength="255" type="text">
										</div>
									</span>
								</fieldset>
							</li>
							
							
							<li id="form_prop_scheduling" class="clear advanced_prop form_prop">
								
								<fieldset>
								  <legend>زمانبندی خودکار</legend> 
								 <div style="padding-bottom: 10px">
								 <input id="form_schedule_enable" class="checkbox" value="" style="float: left"  type="checkbox">
								 <label class="choice" style="float: left;margin-left: 5px;margin-right:3px;line-height: 1.7" for="form_schedule_enable">فعال کردن زمانبندی خودکار</label>
								  <img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="اگر شما میخواهید زمان بندی کنید فرمتان را در یک زمان معین ، تنها این گزینه را فعال کنید"/>
								 </div>
								<div id="form_prop_scheduling_start" style="display: none">
								
									<label class="desc">تنها پذیرفته شود ارسالی ها از تاریخ : </label> 
									
									<span>
									<input type="text" value="" maxlength="2" size="2" style="width: 1em;" class="text" name="scheduling_start_mm" id="scheduling_start_mm">
									<label for="scheduling_start_mm">ماه</label>
									</span>
									
									<span>
									<input type="text" value="" maxlength="2" size="2" style="width: 1em;" class="text" name="scheduling_start_dd" id="scheduling_start_dd">
									<label for="scheduling_start_dd">روز</label>
									</span>
									
									<span>
									 <input type="text" value="" maxlength="4" size="4" style="width: 2em;" class="text" name="scheduling_start_yyyy" id="scheduling_start_yyyy">
									<label for="scheduling_start_yyyy">سال</label>
									</span>
									
									<span id="scheduling_cal_start">
											<input type="hidden" value="" maxlength="4" size="4" style="width: 2em;" class="text" name="linked_picker_scheduling_start" id="linked_picker_scheduling_start">
											<div style="display: none"><img id="scheduling_start_pick_img" alt="جدول تاریخ ها" src="images/icons/calendar.png" class="trigger" style="margin-top: 3px; cursor: pointer" /></div>
									</span>
									<span>
									<select name="scheduling_start_hour" id="scheduling_start_hour" class="select"> 
									<option value="01">1</option>
									<option value="02">2</option>
									<option value="03">3</option>
									<option value="04">4</option>
									<option value="05">5</option>
									<option value="06">6</option>
									<option value="07">7</option>
									<option value="08">8</option>
									<option value="09">9</option>
									<option value="10">10</option>
									<option value="11">11</option>
									<option value="12">12</option>
									</select>
									<label for="scheduling_start_hour">ساعت</label>
									</span>
									<span>
									<select name="scheduling_start_minute" id="scheduling_start_minute" class="select"> 
									<option value="00">00</option>
									<option value="15">15</option>
									<option value="30">30</option>
									<option value="45">45</option>
									</select>
									<label for="scheduling_start_minute">دقیقه</label>
									</span>
									<span>
									<select name="scheduling_start_ampm" id="scheduling_start_ampm" class="select"> 
									<option value="am">ق.ظ</option>
									<option value="pm">ب.ظ</option>
									</select>
									<label for="scheduling_start_ampm">ق.ظ/ب.ظ</label>
									</span>

								</div>
									
								<div id="form_prop_scheduling_end" style="display: none">
								
									<br /><label class="desc">تا تاریخ : </label><br />
									<span>
									<input type="text" value="" maxlength="2" size="2" style="width: 1em;" class="text" name="scheduling_end_mm" id="scheduling_end_mm">
									<label for="scheduling_end_mm">ماه</label>
									</span>
									
									<span>
									<input type="text" value="" maxlength="2" size="2" style="width: 1em;" class="text" name="scheduling_end_dd" id="scheduling_end_dd">
									<label for="scheduling_end_dd">روز</label>
									</span>
									
									<span>
									 <input type="text" value="" maxlength="4" size="4" style="width: 2em;" class="text" name="scheduling_end_yyyy" id="scheduling_end_yyyy">
									<label for="scheduling_end_yyyy">سال</label>
									</span>
									
									<span id="scheduling_cal_end">
											<input type="hidden" value="" maxlength="4" size="4" style="width: 2em;" class="text" name="linked_picker_scheduling_end" id="linked_picker_scheduling_end">
											<div style="display: none"><img id="scheduling_end_pick_img" alt="جدول تاریخ ها" src="images/icons/calendar.png" class="trigger" style="margin-top: 3px; cursor: pointer" /></div>
									</span>
									<span>
									<select name="scheduling_end_hour" id="scheduling_end_hour" class="select"> 
									<option value="01">1</option>
									<option value="02">2</option>
									<option value="03">3</option>
									<option value="04">4</option>
									<option value="05">5</option>
									<option value="06">6</option>
									<option value="07">7</option>
									<option value="08">8</option>
									<option value="09">9</option>
									<option value="10">10</option>
									<option value="11">11</option>
									<option value="12">12</option>
									</select>
									<label for="scheduling_end_hour">ساعت</label>
									</span>
									<span>
									<select name="scheduling_end_minute" id="scheduling_end_minute" class="select"> 
									<option value="00">00</option>
									<option value="15">15</option>
									<option value="30">30</option>
									<option value="45">45</option>
									</select>
									<label for="scheduling_end_minute">دقیقه</label>
									</span>
									<span>
									<select name="scheduling_end_ampm" id="scheduling_end_ampm" class="select"> 
									<option value="am">ق.ظ</option>
									<option value="pm">ب.ظ</option>
									</select>
									<label for="scheduling_end_ampm">ق.ظ/ب.ظ</label>
									</span>

								</div>
								
								</fieldset>
							</li>

							<li id="form_prop_advanced_option" class="clear advanced_prop form_prop">
								
								<fieldset>
								  <legend>گزینه های پیشرفته</legend> 
								  <div style="padding-bottom: 10px">
										 <input id="form_custom_script_enable" class="checkbox" value="1" style="float: left"  type="checkbox">
										 <label class="choice" style="float: left;margin-left: 5px;margin-right:3px;line-height: 1.7" for="form_custom_script_enable">بارگذاری سفارشی فایل جاوا اسکریپت</label>
										 <img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="شما می توانید فایل های جاوا اسکریپت خود را با اجرای دورن خطی با فرم به نمایش بگذارید . و هر بار همراه با فرم به نمایش در خواهد آمد"/>
								  </div>
								  <div id="form_custom_script_div" style="display: none; margin-left: 25px;margin-bottom: 10px">
										<label class="desc" for="form_custom_script_url">آدرس فایل جاوا اسکریپت:</label>
										<input id="form_custom_script_url" name="form_custom_script_url" style="width: 90%" class="text" value=""  type="text">
								  </div>
					
								</fieldset>
							</li>
							
							<li id="form_prop_breaker" class="clear advanced_prop form_prop"></li>
							
							<li id="prop_pagination_style" class="clear">
								<fieldset class="choices">
								<legend>
									استایل صفحه بندی سر برگ 
									<img class="helpmsg" src="images/icons/help3.png" style="vertical-align: top; " title="هنگامی که دارید یک فرم چند صفحه ای ، صفحه بندی سربرگ نمایش داده خواهد شد در بالای فرم شما و به کاربر اجازه می دهد که بداند در کدام مرحله میباشد. این بسیار مفید است و کمک میکند به کاربران تا بفهمند که چه مقدار از فرم را تکمیل کرده اند و چه مقدار از فرم باقی مانده"/>
								</legend>
								<ul>
									<li>
										<input type="radio" id="pagination_style_steps" name="pagination_style" class="choices_default" title="Complete Steps">
										<label for="pagination_style_steps" class="choice">تکمیل مراحل</label>
										<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="یک سری کامل از عناوین صفحات نمایش داده خواهد شد ، همراه باشماره صفحه. عنوان صفحه مربوطه مشخص خواهد کرد برای ادامه به صفحات بعدی.استفاده کنید از این استایل اگر شما صفحات کمی دارید"/>
									</li>
									<li>
										<input type="radio" id="pagination_style_percentage" name="pagination_style" class="choices_default" title="Progress Bar">
										<label for="pagination_style_percentage" class="choice">نوار پیشرفت</label>
										<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="یک نوار پیشرفت با تعداد درصد و عنوان صفحه فعال فعلی نمایش داده خواهد شد. استفاده کنید از این استایل اگر دارید صفحات زیاد یا شما نیاز به قرار دادن عنوان صفحه دیگر برای هر صفحه دارید."/>
									</li>
									<li>
										<input type="radio" id="pagination_style_disabled" name="pagination_style" class="choices_default" title="Disable Pagination Header">
										<label for="pagination_style_disabled" class="choice">غیر فعال کردن صفحه بندی سربرگ</label>
										<img class="helpmsg" src="images/icons/help2.png" style="vertical-align: top" title="انتخاب کنید این گزینه را اگر شما ترجیح میدهید غیر فعال کنید صفحه بندی سربرگ را"/>
									</li>
								</ul>	
							</fieldset>
							</li>
							
							<li id="prop_pagination_titles" class="clear">
								<fieldset class="choices">
								<legend>
									عنوان صفحه
									<img class="helpmsg" src="images/icons/help3.png" style="vertical-align: top; " title="هر صفحه در فرم شما برای خودش عنوانی دارد، شما میتوانید در اینجا مشخص کنید.این برای سازماندهی فرم را به گروه های مطالب معنی دار مفید است. اطمینان حاصل شود که در عناوین صفحات فرم شما مطابق انتظارات شما و مشتریان شما و مختصر توضیح آنچه در هر صفحه است."/>
								</legend>
								<ul id="pagination_title_list">
									<li>
										<label for="pagetitleinput_1">1.</label> 
										<input type="text" value="" autocomplete="off" class="text" id="pagetitle_1" /> 
									</li>	
								</ul>	
							</fieldset>
								
							</li>
							
							</ul>
						</form>
						<!--  end form properties pane -->

						<div class="bullet_bar_bottom">
							<img style="float: left" src="images/bullet_pink.png" />
							<img style="float: right" src="images/bullet_pink.png"/>
						</div>
				</div>
			</div>
		</div>
	</div>			
</div><!-- /#sidebar -->

<div id="dialog-message" title="خطا. سیستم قادر به تکمیل کار نیست." class="buttons" style="display: none">
	<img src="images/icons/warning.png" title="خطر" /> 
	<p>
		ما عذر خواهی میکنیم ، ما نمیتوانیم به سرور متصل شویم<br/>
		لطفا چند دقیقه دیگر دوباره امتحان کنید<br/><br/>
اگر مشکل ادامه داشت لطفا با ما تماس بگیرید و ما در اسرع وقت پاسخگو خواهیم بود!	</p>
</div>
<div id="dialog-warning" title="عنوان خطا" class="buttons" style="display: none">
	<img src="images/icons/warning.png" title="خطر" /> 
	<p id="dialog-warning-msg">
خطا	</p>
</div>
<div id="dialog-confirm-field-delete" title="آیا شما اطمینان دارید که این فیلد حذف شود؟" class="buttons" style="display: none">
	<span class="icon-bubble-notification"></span> 
	<p>
		این عمل قابل بازگشت نیست.<br/>
		<strong>تمامی اطلاعات جمع آوری شده توسط این فیلد نیز حذف خواهد شد</strong><br/><br/>
		اگر شما مطمئن هستید میتوانید ادامه دهید حذف این فرم را<br /><br />
	</p>
	
</div>
<div id="dialog-form-saved" title="موفقیت آمیز!فرم شما ذخیره سازی شد." class="buttons" style="display: none">
	<span class="icon-checkmark-circle"></span> 
	<p>
		<strong>آیا شما می خواهید ادامه ویرایش فرم را انجام بدهید؟</strong><br/><br/>
	</p>	
</div>
<div id="dialog-insert-choices" title="وارد کردن چندین انتخاب" class="buttons" style="display: none"> 
	<form class="dialog-form">				
		<ul>
			<li>
				<label for="bulk_insert_choices" class="description">شما می توانید یک لیست از گزینه های را در اینجا وارد کنید. جدا انتخاب با خط جدید.  </label>
				<div>
				<textarea cols="90" rows="8" class="element textarea medium" name="bulk_insert_choices" id="bulk_insert_choices"></textarea> 
				</div> 
			</li>
		</ul>
	</form>
</div>
<div id="dialog-insert-matrix-rows" title="درج چندین ردیف" class="buttons" style="display: none"> 
	<form class="dialog-form">				
		<ul>
			<li>
				<label for="bulk_insert_rows" class="description">شما می توانید یک لیست از ردیف در اینجا وارد کنید. جدا کردن سطر با خط جدید.</label>
				<div>
				<textarea cols="90" rows="8" class="element textarea medium" name="bulk_insert_rows" id="bulk_insert_rows"></textarea> 
				</div> 
			</li>
		</ul>
	</form>
</div>
<div id="dialog-insert-matrix-columns" title="درج چندین ستون" class="buttons" style="display: none"> 
	<form class="dialog-form">				
		<ul>
			<li>
				<label for="bulk_insert_columns" class="description">شما می توانید یک لیست از ستون ها در اینجا وارد کنید. جدا کردن برچسب با خط جدید. </label>
				<div>
				<textarea cols="90" rows="8" class="element textarea medium" name="bulk_insert_columns" id="bulk_insert_columns"></textarea> 
				</div> 
			</li>
		</ul>
	</form>
</div>  
<?php
	$footer_data =<<<EOT
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.core.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.widget.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.tabs.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.mouse.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.sortable.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.draggable.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.position.js"></script>
<script type="text/javascript" src="js/jquery-ui/ui/jquery.ui.dialog.js"></script>
<script type="text/javascript" src="js/jquery.tools.min.js"></script>
<script type="text/javascript" src="js/builder.js"></script>
<script type="text/javascript" src="js/datepick/jquery.datepick.js"></script>
<script type="text/javascript" src="js/datepicker/persian-date.min.js"></script>
<script type="text/javascript" src="js/datepicker/persian-datepicker.min.js"></script>
<script type="text/javascript">
	$(function(){
		{$jquery_data_code}		
    });
	
	$( document ).ready(function() {

var rangMin = $('#date_range_min_shamsi').persianDatepicker({ 
	    		
				  "inline": false,
				  "format": "L",
				  "viewMode": "day",
				  "initialValue": false,
				  "initialValueType": "persian",
				  //"minDate": "",
				  //"maxDate": "",
				  "autoClose": false,
				  "position": "auto",
				  "altFormat": "L",
				  "altField": "#altfieldExample",
				  "onlyTimePicker": false,
				  "onlySelectOnDate": true,
				  "calendarType": "persian",
				  "inputDelay": 800,
				  "observer": false,
				  "calendar": {
					"persian": {
					  "locale": "fa",
					  "showHint": true,
					  "leapYearMode": "algorithmic"
					},
					"gregorian": {
					  "locale": "en",
					  "showHint": false
					}
				  },
				  "navigator": {
					"enabled": true,
					"scroll": {
					  "enabled": true
					},
					"text": {
					  "btnNextText": "<",
					  "btnPrevText": ">"
					}
				  },
				  "toolbox": {
					"enabled": true,
					"calendarSwitch": {
					  "enabled": false,
					  "format": "MMMM"
					},
					"todayButton": {
					  "enabled": true,
					  "text": {
						"fa": "امروز",
						"en": "Today"
					  }
					},
					"submitButton": {
					  "enabled": true,
					  "text": {
						"fa": "تایید",
						"en": "Submit"
					  }
					},
					"text": {
					  "btnToday": "امروز"
					}
				  },
				  "timePicker": {
					"enabled": false,
					"step": 1,
					"hour": {
					  "enabled": true,
					  "step": null
					},
					"minute": {
					  "enabled": true,
					  "step": null
					},
					"second": {
					  "enabled": true,
					  "step": null
					},
					"meridian": {
					  "enabled": true
					}
				  },
				  "dayPicker": {
					"enabled": true,
					"titleFormat": "YYYY MMMM"
				  },
				  "monthPicker": {
					"enabled": true,
					"titleFormat": "YYYY"
				  },
				  "yearPicker": {
					"enabled": true,
					"titleFormat": "YYYY"
				  },
				  "responsive": false,
				   initialValue: false,
				  onSelect: function(unix){
					  console.log(unix);
					var date = new Date(unix);
					var year = date.getFullYear();
					var month = date.getMonth() + 1;
					var day = date.getDate();
					var dateString = year + "-" + month + "-" + day;
					
					if(typeof unix === 'undefined'){
						//$('#date_range_min_dd').val("");
						//$('#date_range_min_mm').val("");
						//$('#date_range_min_yyyy').val("");
						$('#date_range_min_shamsi').val("");
						$("#linked_picker_range_min").datepick("setDate", "");
					}else{
						//$('#date_range_min_dd').val(day);
						//$('#date_range_min_mm').val(month);
						//$('#date_range_min_yyyy').val(year);
						$("#linked_picker_range_min").datepick("setDate", new Date(parseInt(year, 10), parseInt(month, 10) - 1, parseInt(day, 10)));
					}
				},
				/*
				onSet: function(e){
					console.log(typeof e.model);
					if(typeof e.model === 'undefined')
					{
						$('#date_range_min_dd').val("");
						$('#date_range_min_mm').val("");
						$('#date_range_min_yyyy').val("");
						$("#linked_picker_range_min").datepick("setDate", "");
					}				
				},  */
				
			});	
			
			
var rangMax = $('#date_range_max_shamsi').persianDatepicker({ 
	    		
				  "inline": false,
				  "format": "L",
				  "viewMode": "day",
				  "initialValue": false,
				  "initialValueType": "persian",
				  //"minDate": "",
				  //"maxDate": "",
				  "autoClose": false,
				  "position": "auto",
				  "altFormat": "L",
				  "altField": "#altfieldExample",
				  "onlyTimePicker": false,
				  "onlySelectOnDate": true,
				  "calendarType": "persian",
				  "inputDelay": 800,
				  "observer": false,
				  "calendar": {
					"persian": {
					  "locale": "fa",
					  "showHint": true,
					  "leapYearMode": "algorithmic"
					},
					"gregorian": {
					  "locale": "en",
					  "showHint": false
					}
				  },
				  "navigator": {
					"enabled": true,
					"scroll": {
					  "enabled": true
					},
					"text": {
					  "btnNextText": "<",
					  "btnPrevText": ">"
					}
				  },
				  "toolbox": {
					"enabled": true,
					"calendarSwitch": {
					  "enabled": false,
					  "format": "MMMM"
					},
					"todayButton": {
					  "enabled": true,
					  "text": {
						"fa": "امروز",
						"en": "Today"
					  }
					},
					"submitButton": {
					  "enabled": true,
					  "text": {
						"fa": "تایید",
						"en": "Submit"
					  }
					},
					"text": {
					  "btnToday": "امروز"
					}
				  },
				  "timePicker": {
					"enabled": false,
					"step": 1,
					"hour": {
					  "enabled": true,
					  "step": null
					},
					"minute": {
					  "enabled": true,
					  "step": null
					},
					"second": {
					  "enabled": true,
					  "step": null
					},
					"meridian": {
					  "enabled": true
					}
				  },
				  "dayPicker": {
					"enabled": true,
					"titleFormat": "YYYY MMMM"
				  },
				  "monthPicker": {
					"enabled": true,
					"titleFormat": "YYYY"
				  },
				  "yearPicker": {
					"enabled": true,
					"titleFormat": "YYYY"
				  },
				  "responsive": false,
				   initialValue: false,
				  onSelect: function(unix){
					  
					var date = new Date(unix);
					var year = date.getFullYear();
					var month = date.getMonth() + 1;
					var day = date.getDate();
					var dateString = year + "-" + month + "-" + day;
					console.log('datepicker select : ' + date);
					if(typeof unix === 'undefined'){
						//$('#date_range_max_dd').val("");
						//$('#date_range_max_mm').val("");
						//$('#date_range_max_yyyy').val("");
						$('#date_range_max_shamsi').val("");
						$("#linked_picker_range_max").datepick("setDate", "");
					}else{
						//$('#date_range_max_dd').val(day);
						//$('#date_range_max_mm').val(month);
						//$('#date_range_max_yyyy').val(year);
						$("#linked_picker_range_max").datepick("setDate", new Date(parseInt(year, 10), parseInt(month, 10) - 1, parseInt(day, 10)));
					}
				},
/* 				
				onSet: function(e){
					console.log(typeof e.model);
					if(typeof e.model === 'undefined')
					{
						$('#date_range_max_dd').val("");
						$('#date_range_max_mm').val("");
						$('#date_range_max_yyyy').val("");
					}				
				},
				 */
			});	
			
			
			
			
			
			$('#date_range_min_shamsi_pick_img').click(function(event){
				event.preventDefault();
				$('#date_range_min_shamsi').click();
			});
			$('#date_range_max_shamsi_pick_img').click(function(event){
				event.preventDefault();
				$('#date_range_max_shamsi').click();
			});
			
			
			
			});
	
</script>
EOT;
	require('includes/footer.php'); 
?>