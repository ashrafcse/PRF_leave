<?php
// include/header.php
require_once __DIR__ . '/helpers.php'; // BASE_URL, asset(), h()
// header("Content-Security-Policy: default-src 'self'; img-src 'self' data:;");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <title>Dashboard</title>
  

  <!-- Local CSS via asset() — ফোল্ডারে থাকা নাম অনুযায়ী -->
  <link rel="stylesheet" href="<?php echo h(asset('css/bootstrap1.min.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('css/metisMenu.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('css/style1.css')); ?>" />
  <!-- colors/default.css আপনার ফোল্ডারে নেই, তাই বাদ -->
  <!-- বাকি vendor CSS ফাইলগুলো থাকলে রাখুন, না থাকলে কমেন্ট করুন -->
  <link rel="stylesheet" href="<?php echo h(asset('vendors/themefy_icon/themify-icons.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/niceselect/css/nice-select.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/owl_carousel/css/owl.carousel.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/gijgo/gijgo.min.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/font_awesome/css/all.min.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/tagsinput/tagsinput.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/datepicker/date-picker.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/vectormap-home/vectormap-2.0.2.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/scroll/scrollable.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/datatable/css/jquery.dataTables.min.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/datatable/css/responsive.dataTables.min.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/datatable/css/buttons.dataTables.min.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/text_editor/summernote-bs4.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/morris/morris.css')); ?>" />
  <link rel="stylesheet" href="<?php echo h(asset('vendors/material_icon/material-icons.css')); ?>" />

  <!-- Optional CDN icons -->
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer" />
        <!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Bootstrap JS + Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">



</head>
<body class="crm_body_bg">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<section class="main_content dashboard_part large_header_bg" style="padding-bottom: 39px;">
<?php require_once __DIR__ . '/navbar.php'; ?>
<div class="main_content_iner overly_inners">
  <div class="container-fluid p-0">
