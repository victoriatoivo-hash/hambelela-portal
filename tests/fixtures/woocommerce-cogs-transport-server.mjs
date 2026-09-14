import http from 'node:http';

const port = Number(process.env.PHASE3_MOCK_PORT || 18731);
const server = http.createServer(async (request, response) => {
  const url = new URL(request.url, `http://127.0.0.1:${port}`);
  if (url.searchParams.get('consumer_key') !== 'synthetic-key' || url.searchParams.get('consumer_secret') !== 'synthetic-secret') {
    response.writeHead(401, {'Content-Type':'application/json'}).end(JSON.stringify({code:'woocommerce_rest_cannot_view',message:'Synthetic authentication failed.'}));
    return;
  }
  let body = '';
  for await (const chunk of request) body += chunk;
  const variation = url.pathname.includes('/variations/');
  if (request.method === 'GET') {
    response.writeHead(200, {'Content-Type':'application/json'}).end(JSON.stringify({id:variation?81:41,cost_of_goods_sold:{values:[{defined_value:12.3456,effective_value:12.3456}],total_value:12.3456,...(variation?{defined_value_is_additive:false}:{})}}));
    return;
  }
  if (request.method === 'PUT') {
    const parsed = JSON.parse(body);
    const defined = parsed.cost_of_goods_sold.values[0].defined_value;
    response.writeHead(200, {'Content-Type':'application/json'}).end(JSON.stringify({id:variation?81:41,cost_of_goods_sold:{values:[{defined_value:defined,effective_value:defined}],total_value:defined,...(variation?{defined_value_is_additive:false}:{})}}));
    return;
  }
  response.writeHead(405).end();
});
server.listen(port, '127.0.0.1');
