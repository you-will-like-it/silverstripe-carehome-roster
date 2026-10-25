<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>$Heading</title>
    $Styles
</head>
<body class="rr-body">
<header class="rr-head">
    <div>
        <h1>$Heading</h1>
        <p class="rr-muted"><% if $Facility %>$Facility · <% end_if %><%t YouWillLikeIT\\CareHome\\Roster\\Report.STATUS "Status rasporeda" %>: <strong>$PeriodStatus</strong><% if not $IsLocked %> · <%t YouWillLikeIT\\CareHome\\Roster\\Report.NOT_FINAL "mjesec nije zaključan, brojke se još mogu mijenjati" %><% end_if %></p>
    </div>
    <nav class="rr-nav">
        <a href="$PrevLink">&lsaquo;</a>
        <% loop $Groups %><a href="$Link" class="<% if $Current %>rr-current<% end_if %>">$Title</a><% end_loop %>
        <a href="$NextLink">&rsaquo;</a>
        <a href="$CsvLink" class="rr-btn">CSV</a>
        <a href="$PdfLink" class="rr-btn" target="_blank" rel="noopener">PDF</a>
        <a href="$BoardLink" class="rr-btn"><%t YouWillLikeIT\\CareHome\\Roster\\Report.BOARD "Planer" %></a>
    </nav>
</header>
<main class="rr-main">
    <% include YouWillLikeIT\\CareHome\\Roster\\Report\\HoursTable %>
</main>
</body>
</html>
