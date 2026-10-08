<?php
defined('IN.XPHP') || exit('This system Access Denied');
/* XPHP Version 2.76.618, Create on 2026-09-10 17:45:06, instructions: There is config */
return [
  'name' => '异步队列(Redis)',
  'author' => 'XPHP',
  'site' => 'https://x-php.com/',
  'tables' => 'queue',
  'description' => '消息的发布，获取，执行，删除，重发，失败处理，延迟执行，超时控制等!',
  'composer' => [
    'symfony/process' => '^5.0',
    'nesbot/carbon' => '^2.16',
  ],
  'isphar' => true,
  'icon' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAABYWlDQ1BJQ0MgUHJvZmlsZQAAKJFtkLFLAmEYxp8rQzCjKJGghoMaHCzsdHATs6jAQSzB2u5O0+A8P+4uwqmGaBeaW2zoL8glIoKWWoKgwQhaojUIXEqu9/Mqtfo+Xp4fD+/78vIAfV6ZMc0FoKRbRnppXsyub4juFwxiCB74MSGrJounUklqwbf2vuY9BK53M3yX7/z5ajR7PRXYvRl7rccaf/t7nieXN1XSDypJZYYFCCHi1I7FOO8R+ww6iviQc8HhE86Kw2ftnrV0gviWeEQtyjniJ+Kg0uUXurikbatfN/DrvXk9s0rqp5rEAhaRpC8iAwkRzFEtU0b/z0TaMwmUwVCBgS0UUIRF03FyGDTkiVegQ8UsgsQSQlRhnvXvDDte+RiINoH+asdTKIP6ATDe6HjTR8DwPnB6yWRD/klWaLrMzbDksDcGDDza9lsAcFeBVtW232u23arR/gfgovIJlw9kdxuRck4AAAA4ZVhJZk1NACoAAAAIAAGHaQAEAAAAAQAAABoAAAAAAAKgAgAEAAAAAQAAAECgAwAEAAAAAQAAAEAAAAAAZZlgigAAA8dJREFUeAHtW71yEzEQvrOdQAEDZWgo/Ap+gbyEm/Q8CC0tHfS8DONXcJsWGoYh8Rxa5zbZW62k1d3a1vmsDKOT7Pv0fZ9Wv4zr9Xp9vd1um81m81AJqbn93AjVz1VN1VSP7h9Pi6re//H6VPnYeLOY+NLIpvjwzzVmzkI9z8F4WQPO34mVT4U3i5EKfXYqsiE+vD6HX7YBOeCcmFQ+NV6WAacmKxlI6/rwUxvQB5yS48+l4KkMKIUsNxHLQ/gtPt5+8RdxRHZ5U/0Z9TpPpIiPiQgY9yZHVMwqIwZAYPjBMZYdHtMZLAYMmIZ4cGXhW3Nc8fc/PvkUSM2Hu++kBDFpOyxZBBxXfEeZomAtHvCIAdMTD6fY1gBZfDWSI60ieDpfoZHkDAiLBwNyEwWn75a6erQGUKrwDMILEN/YTnhS55A5AE0oR/xut0NSz7l1JDEDyhLfuAigyVo84BEDpicebi1bA6YpHqLLGVCu+Lqu3Vb1sLfLrQF0pKWfpdkU3upN1o11mPDomAfx8/l8Lz/NqPuNHH7CWaALxksp8NTe3sOLiXcm5OLd3H3Lur8gkyCn5pdT4v034jXQ48Ged+JzE+Dl/ieN2oAxiO+zb1AZMBbxdA6B6NHMSUkDzlk8mJQwIH9MAWgoHWLM8zkE2tb0PHKMGCCfEnPAsRHIjyG+z74hYMA4xffZNwj7gGHic+/wUut8Lh6NOs0zi4Bh4nmD5hOo8YUo8CMGTE/85U7QhayLALnnizklHiDs6Xa5NYCP3nKOyJQssuy9FAtmkjkA4csR32dvjyp4HpqQmQFlie+zt+fCoRwSD5FEDJie+MudoIsOFwHl9nyfvT0OgVjYQ89jag3Aoi7XguvQEgclQlaNJ8z28K60eghngXgz1uJz7/Di7OITHu15xCGTIFaFc2vxJeCpDSiBbLhr8nsesVQGnKt4MCFpwDmLVxhgfCeYMTtjiMZyi86JRIB8SpSWkhhJ/MyCLGJBboUXMGAa4sFIwYDpiBcMmJZ4GEYkAqYn/nIn6MaAOwvIPY+nxJuHx+rvv18wXJSpcccXwOwmqH3C7NanS8Pwfv/8+nL0axtbrVZXy+VyX++GgE8WxcP3X129rV5fv0/z3H9jGFm/EWu8qqLioT0yB2Dzfk/pTLAma43ni4cfjTIDfPFoS9wEa7LWeLJ4+NEoMSAsPm6CNVlrvLB40NUakBYvm2BN1h6Pj3n+W2lngF5814R3Rc32yO0lfzITZ3uo5+KhrjUAHnNS41aHN97qAE2eYqnzmfuRJImH9/4DveilLx5fsP0AAAAASUVORK5CYII=',
  'version' => '0.28.618',
  'created_at' => '2025-08-11 17:31:21',
  'updated_at' => '2026-09-10 17:45:06',
];