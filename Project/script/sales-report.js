document.addEventListener("DOMContentLoaded", function(){

const ctx = document.getElementById("salesChart");

const select = document.getElementById("timeframeSelect");

const chartData = {
    Weekly: [],
    Monthly: [],
    Yearly: []
};

function getData(period){

    if(chartData[period].length === 0){
        return {
            labels:["No Sales Data"],
            data:[1],
            colors:["#cccccc"]
        };
    }

    return {
        labels:["Pork Siomai","Beef Siomai","Japanese Siomai"],
        data:chartData[period],
        colors:["#ff6384","#36a2eb","#ffce56"]
    };
}

let start = getData("Weekly");

const salesChart = new Chart(ctx,{
    type:"pie",
    data:{
        labels:start.labels,
        datasets:[{
            data:start.data,
            backgroundColor:start.colors
        }]
    },
    options:{
        responsive:true,
        maintainAspectRatio:false
    }
});

select.addEventListener("change",function(){

    let result = getData(this.value);

    salesChart.data.labels = result.labels;
    salesChart.data.datasets[0].data = result.data;
    salesChart.data.datasets[0].backgroundColor = result.colors;

    salesChart.update();

});

});