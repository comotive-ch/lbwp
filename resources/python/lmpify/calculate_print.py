from stl import mesh
import json
import numpy as np
import argparse, sys
import trimesh
import math

# Read settings from command line
parser = argparse.ArgumentParser()

parser.add_argument("--f", help="The STL/3MF File to print")
parser.add_argument("--lh", help="The layer height setting for the slicer")
parser.add_argument("--ps", help="The average print speed")
parser.add_argument("--lw", help="The line with setting for the slicer")
parser.add_argument("--mc", help="The material cost for the filament")
parser.add_argument("--s", help="The model scaling factor in percent")

args = parser.parse_args()
settings = vars(args)

for s in settings:
    if settings[s] is None and s != "s":
        print(json.dumps({"error": f"Please enter a value for {s}"}))
        exit()

mesh = trimesh.load(settings["f"] , force='scene')
layer_height = float(settings["lh"])  # Set the layer height in mm
print_speed = float(settings["ps"])  # Set the print speed in mm/s
line_width = float(settings["lw"])
material_kg_cost = float(settings["mc"])  # Material cost in CHF per kg
density = 1.25 # Default material density in g/cm³

if settings["s"] is not None:
    scale = float(settings["s"]) / 100
    print_speed = print_speed * scale
    mesh = mesh.scaled(scale)

# Fix values for calculations
support_multiplier = 1.5
volumetric_speed = line_width * layer_height * print_speed
power_watt = 144.08  # Power in Watt
electricity_rate = 0.3143  # Electricity rate in CHF/kWh
tear_per_hour = 0.5
cost_margin = 1.3
profit_margin = 5

def get_objects_volumes():
    """
    Calculate the volume of each object in a .3mf file.

    Parameters:
        file_path (str): The path to the .3mf file.

    Returns:
        dict: A dictionary with object names as keys and their volumes as values.
    """

    # Get the bounding box of the mesh
    z_min, z_max = mesh.bounds[:, 2]

    # Create slicing planes at intervals of layer_height
    z_positions = np.arange(z_min, z_max, layer_height)

    layer_areas = []
    layer_weights = 0.0

    for name, geometry in mesh.geometry.items():
        # Get geometry size (x, y and z)
        size = geometry.bounds[1] - geometry.bounds[0]
        # And check if geometry is too big
        if size[0] > 256 or size[1] > 256 or size[2] > 256:
            print(json.dumps({"error": "The model is too big"}))

        # Also check if the model is a bigger shipping size
        if size[0] > 170 or size[1] > 170 or size[2] > 170:
            profit_margin = 10

        for z in z_positions:
            # Define the slicing plane
            plane_origin = np.array([0, 0, z])
            plane_normal = np.array([0, 0, 1])  # Slicing horizontally

            # Slice the geometry at the specified height
            slice_section = geometry.section(plane_origin=plane_origin, plane_normal=plane_normal)

            if slice_section is not None:
                plane = slice_section.to_planar()[0]
                layer_areas.append(plane.area)
                layer_weights += (plane.area * layer_height) * density / 1000  # Convert to grams

    return layer_areas, layer_weights

def get_layers_data(mesh, layer_height=0.2, density=1.25):
    """
    Calculate the volume of each layer in a 3D mesh and the total weight

    Parameters:
        mesh (trimesh.Trimesh): The 3D mesh object.
        layer_height (float): The height of each layer.

    Returns:
        list of float: Volumes of each layer.
    """
    # Get the bounding box of the mesh
    z_min, z_max = mesh.bounds[:, 2]

    # Create slicing planes at intervals of layer_height
    z_positions = np.arange(z_min, z_max, layer_height)

    layer_areas = []
    layer_weights = 0.0

    for z in z_positions:
        # Define the slicing plane
        plane_origin = np.array([0, 0, z])
        plane_normal = np.array([0, 0, 1])  # Slicing horizontally

        # Slice the mesh at this layer
        slice_section = mesh.section(plane_origin=plane_origin, plane_normal=plane_normal)

        if slice_section is not None:
            plane = slice_section.to_planar()[0]
            x_values = plane.vertices[:, 0]
            y_values = plane.vertices[:, 1]
            width = x_values.max() - x_values.min()
            length = y_values.max() - y_values.min()

            if z > 10:
                print(width, length)
                slice_section.show()
                exit()

            layer_areas.append(plane.area)
            layer_weights += (plane.area * layer_height) * density / 1000  # Convert to grams

    return layer_areas, layer_weights

def calculate_electricity_cost(power_watt, electricity_rate, print_time_hours):
    """
    Calculate printing electricity cost

    :param power_watt: the power in kilowatt
    :param electricity_rate: cost of electricity
    :param print_time_hours: print time

    :return: the estimated consumed energy and cost rounded to the next 0.5
    """
    # Convert power from Watts to kW
    power_kw = power_watt / 1000

    # Calculate energy consumption in kWh
    energy_consumption = power_kw * print_time_hours

    # Calculate raw cost
    raw_cost = energy_consumption * electricity_rate

    # Round up to the next 0.5 €
    rounded_cost = math.ceil(raw_cost * 2) / 2

    return energy_consumption, rounded_cost


def calculate_print_cost():
    # areas, weight = get_layers_data(mesh, layer_height=layer_height)
    areas, weight = get_objects_volumes()
    time = 0.0

    # Loop through layers to calculate time
    for i in range(len(areas)):
        area = areas[i]
        layer_volume = area * layer_height
        layer_time = layer_volume / volumetric_speed

        time += layer_time

    preparation_time = 10 * 60  # 10 minutes in seconds
    time = time * support_multiplier + preparation_time
    weight = weight * support_multiplier
    # Calculate electricity cost

    time_hours = time / 60 / 60  # Print time in hours
    energy, cost = calculate_electricity_cost(power_watt, electricity_rate, time_hours)

    # Calculate additional costs
    tear_cost = tear_per_hour * time_hours
    material_cost = material_kg_cost * weight / 1000  # Material cost in CHF

    # Finally the total cost adding some "safety" margin and also a small profit
    total_cost = (cost + material_cost + tear_cost) * cost_margin + profit_margin
    total_cost = math.ceil(total_cost * 2) / 2

    cost_data = {
        "weight" : weight,
        "time" : time,
        "cost" : total_cost,
        "electricity": energy,
    }

    print(json.dumps(cost_data))

calculate_print_cost()